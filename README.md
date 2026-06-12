# fliix/php-zabbix-sender

PHP implementation of the Zabbix Sender protocol — compatible with **Zabbix 7.x**.

- ✅ Unencrypted connection to Zabbix Server
- ✅ TLS PSK connection to Zabbix Server (TLS 1.2, compatible with Zabbix 7.x / OpenSSL 3.x)

Official Zabbix Sender docs: <https://www.zabbix.com/documentation/current/en/manpages/zabbix_sender>

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | ≥ 8.4 |
| ext-sockets | any |
| openssl binary | any (needed for PSK connections) |

---

## Installation

```shell
composer require fliix-cloud/php-zabbix-sender
```

## Development

```shell
composer test
composer cs
composer check
```

- `composer test` runs the PHPUnit test suite.
- `composer cs` runs php-cs-fixer in dry-run mode.
- `composer check` runs both style checks and tests.

---

## Quick start – unencrypted connection

1. Create a **Trapper** item in Zabbix  
   → <https://www.zabbix.com/documentation/current/en/manual/config/items/itemtypes/trapper>

2. Instantiate the sender:

   ```php
   $sender = new \Fliix\ZabbixSender\ZabbixSender([
       'server' => '127.0.0.1',
       'host'   => 'my-zabbix-host',
   ]);
   ```

3. Send a single value:

   ```php
   $sender->send('my.trapper.key', 'some value');
   ```

4. Check the result:

   ```php
   $info = $sender->getLastResponseInfo();
   echo $info->getTotal();     // total items submitted
   echo $info->getProcessed(); // items accepted by Zabbix
   echo $info->getFailed();    // items rejected by Zabbix
   echo $info->getSpent();     // processing time in seconds
   ```

---

## Batch mode

Collect multiple values and send them in a single request:

```php
$sender = new \Fliix\ZabbixSender\ZabbixSender([
    'server' => '127.0.0.1',
    'host'   => 'my-zabbix-host',
]);

$sender->batch()
    ->send('cpu.load',    '0.42')
    ->send('memory.free', '1024')
    ->send('disk.used',   '512', 'other-host'); // override host per item

$success = $sender->execute();
```

---

## TLS PSK connection (Zabbix 7.x)

### Background

Zabbix supports Pre-Shared Key (PSK) encryption between the sender and the
Zabbix Server/Proxy. PSK uses **TLS 1.2** cipher suites defined in RFC 4279
(e.g. `PSK-AES256-CBC-SHA`). These cipher suites are **not available in
TLS 1.3** and are disabled by default in **OpenSSL 3.x**, which is why the
connection must explicitly use TLS 1.2.

### Step 1 – Generate a PSK key

A PSK key is a hex-encoded random string. Generate one with:

```shell
openssl rand -hex 32
# example output: a3f1b2c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2
```

Keep this value – you will need it both in Zabbix and in your PHP code.

### Step 2 – Configure PSK in Zabbix

1. Open the Zabbix web UI and navigate to **Configuration → Hosts**
2. Select the host that will receive the data.
3. Go to the **Encryption** tab.
4. Set **Connections from host** to **PSK**.
5. Fill in:
   - **PSK identity** – a human-readable name, e.g. `hostname`
   - **PSK** – the hex key generated in Step 1.
6. Save the host.

### Step 3 – Use PSK in PHP

```php
$sender = new \Fliix\ZabbixSender\ZabbixSender([
    'server'           => '127.0.0.1',
    'port'             => 10051,
    'host'             => 'my-zabbix-host',
    'tls-connect'      => 'psk',
    'tls-psk-identity' => 'my-php-sender',
    'tls-psk'          => 'a3f1b2c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2',
]);

$sender->send('my.trapper.key', 'secure value');

$info = $sender->getLastResponseInfo();
echo "Processed: {$info->getProcessed()}, Failed: {$info->getFailed()}";
```

#### Optional: override the PSK cipher

By default, the library uses a TLS 1.2 PSK cipher list to improve
compatibility with different OpenSSL/Zabbix builds:

`PSK-AES128-GCM-SHA256:PSK-AES256-GCM-SHA384:PSK-AES128-CBC-SHA256:PSK-AES256-CBC-SHA384:PSK-AES128-CBC-SHA:PSK-AES256-CBC-SHA`

If your Zabbix Server is configured to use a specific cipher, pass
`tls-cipher` explicitly:

```php
$sender = new \Fliix\ZabbixSender\ZabbixSender([
    'server'           => '127.0.0.1',
    'tls-connect'      => 'psk',
    'tls-psk-identity' => 'my-php-sender',
    'tls-psk'          => 'a3f1b2c4...',
    'tls-cipher'       => 'PSK-AES128-CBC-SHA',
]);
```

### PSK with batch mode

PSK works transparently with batch mode. The same `tls-connect` options are
used — just call `batch()` before sending:

```php
$sender = new \Fliix\ZabbixSender\ZabbixSender([
    'server'           => '127.0.0.1',
    'port'             => 10051,
    'host'             => 'my-zabbix-host',
    'tls-connect'      => 'psk',
    'tls-psk-identity' => 'my-php-sender',
    'tls-psk'          => 'a3f1b2c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2',
]);

$sender->batch()
    ->send('cpu.load',    '0.42')
    ->send('memory.free', '1024')
    ->send('disk.used',   '512', 'other-host'); // override host per item

$success = $sender->execute();
```

### Troubleshooting PSK

| Symptom | Likely cause | Fix |
|---|---|---|
| `SSL alert handshake failure` | Cipher mismatch or TLS version mismatch | Ensure `tls-cipher` matches what Zabbix accepts; Zabbix 7 requires TLS 1.2 |
| `no cipher can be selected` | OpenSSL 3.x with PSK | Use `-tls1_2` and a PSK-specific cipher (already handled by this library) |
| `Processed 0 Failed 1` | Item key or host name mismatch | Verify the Zabbix host name and trapper item key |
| Connection refused | Wrong server/port or firewall | Check `server`/`port` options and Zabbix Server firewall rules |

---

## Protocol compatibility (zabbix_sender)

This library follows the Zabbix sender protocol frame format:

- Header magic/version: `ZBXD\1`
- Header length: 13 bytes total
- Data length: 8 bytes little-endian (`pack("VV", $length, 0x00)`)
- Payload: JSON with `request: "sender data"` and `data: [...]`

This matches the Zabbix protocol docs for `zabbix_sender` and
`header_datalen`.

---

## All available options

| Option | Type | Default | Description |
|---|---|---|---|
| `server` | string | *(required)* | Zabbix Server or Proxy hostname / IP |
| `port` | int | `10051` | Zabbix Server port |
| `host` | string | *(required)* | Zabbix host name the data belongs to |
| `tls-connect` | string | `unencrypted` | Encryption mode: `unencrypted`, `psk` |
| `tls-psk-identity` | string | – | PSK identity (required when `tls-connect=psk`) |
| `tls-psk` | string | – | PSK hex key (required when `tls-connect=psk`) |
| `tls-cipher` | string | `PSK-AES128-GCM-SHA256:PSK-AES256-GCM-SHA384:PSK-AES128-CBC-SHA256:PSK-AES256-CBC-SHA384:PSK-AES128-CBC-SHA:PSK-AES256-CBC-SHA` | Override TLS 1.2 cipher for PSK connections |
| `tls-cipher13` | string | – | Override TLS 1.3 ciphersuite (OpenSSL ≥ 1.1.1 only) |

---

## License

Apache-2.0
