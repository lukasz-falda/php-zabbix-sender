<?php

namespace Fliix\ZabbixSender\Resolver;

use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

/**
 * Provides functionality for resolving options of ZabbixSender.
 */
final class OptionsResolver
{
	/**
	 * Resolves ZabbixSender options.
	 */
	public static function resolve(array $options): array
	{
		$resolver = new \Symfony\Component\OptionsResolver\OptionsResolver();

		$resolver
			->define('host')
			->allowedTypes('string');

		$resolver
			->define('server')
			->allowedTypes('string')
			->allowedValues(
				Validation::createIsValidCallable(new Assert\Hostname(requireTld: true)),
				Validation::createIsValidCallable(new Assert\Ip(version: Assert\Ip::ALL))
			);

		$resolver
			->define('port')
			->default(10051)
			->allowedTypes('int');

		$resolver
			->define('timeout')
			->default(30)
			->allowedTypes('int')
			->info('Socket read/write timeout in seconds.');

		$resolver
			->define('tls-connect')
			->allowedTypes('string')
			->info(
				'How to connect to server or proxy. Values: unencrypted - connect without encryption (default); psk - connect using TLS and a pre-shared key.'
			)
			->allowedValues('unencrypted', 'psk')
			->default('unencrypted');

		$resolver
			->define('tls-psk-identity')
			->allowedTypes('string')
			->info('PSK-identity string.');

		$resolver
			->define('tls-psk')
			->allowedTypes('string')
			->info('Pre-shared key (PSK) in hexadecimal string format.')
			->normalize(function (\Symfony\Component\OptionsResolver\OptionsResolver $options, $value) {
				if (!ctype_xdigit($value)) {
					throw new InvalidOptionsException("Invalid PSK format. PSK must be a hexadecimal string.");
				}
				return $value;
			});


		$resolver
			->define('tls-cipher')
			->allowedTypes('string')
			->info(
				'GnuTLS priority string (for TLS 1.2 and up) or OpenSSL cipher string (only for TLS 1.2). Override the default ciphersuite selection criteria.'
			);

		$resolver
			->define('tls-cipher13')
			->allowedTypes('string')
			->info(
				'Cipher string for OpenSSL 1.1.1 or newer for TLS 1.3. Override the default ciphersuite selection criteria. This option is not available if OpenSSL version is less than 1.1.1.'
			);

		// Internal option used after resolving once in ZabbixSender and reusing
		// the same options in concrete connection constructors.
		$resolver
			->define('connection_type')
			->allowedTypes('string')
			->allowedValues('unencrypted', 'psk');

		$connection_type = 'unencrypted';
		if (isset($options['tls-connect']) && $options['tls-connect'] === 'psk') {
			$resolver->setRequired(['tls-psk-identity', 'tls-psk']);
			$connection_type = 'psk';
		}

		return array_merge($resolver->resolve($options), ['connection_type' => $connection_type]);
	}
}
