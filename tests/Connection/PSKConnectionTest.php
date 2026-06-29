<?php

namespace Fliix\ZabbixSender\Tests\Connection;

use Fliix\ZabbixSender\Connection\PskConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class PSKConnectionTest extends TestCase
{
	private function options(): array
	{
		return [
			'server' => '127.0.0.1',
			'tls-connect' => 'psk',
			'tls-psk-identity' => 'TEST-HOST',
			'tls-psk' => 'bb6586c35826ee585b622dc046ea5dad',
		];
	}

	public function testReadAndWriteReturnFalseWhenNotOpened(): void
	{
		$connection = new PskConnection($this->options());

		self::assertFalse($connection->write('payload'));
		self::assertFalse($connection->read());
	}

	public function testBuildsTls12Command(): void
	{
		$connection = new PskConnection($this->options());

		$reflection = new ReflectionClass($connection);
		$property = $reflection->getProperty('command');
		$property->setAccessible(true);
		$command = $property->getValue($connection);

		self::assertStringContainsString('openssl s_client', $command);
		self::assertStringContainsString('-quiet', $command);
		self::assertStringContainsString('-tls1_2', $command);
		self::assertStringContainsString('127.0.0.1:10051', $command);
		self::assertStringNotContainsString('127.0.0.1":10051', $command);
		self::assertStringContainsString('PSK-AES128-GCM-SHA256:PSK-AES256-GCM-SHA384', $command);
	}

	public function testUsesCustomTlsCipherWhenProvided(): void
	{
		$options = $this->options();
		$options['tls-cipher'] = 'PSK-AES128-CBC-SHA256';

		$connection = new PskConnection($options);

		$reflection = new ReflectionClass($connection);
		$property = $reflection->getProperty('command');
		$property->setAccessible(true);
		$command = $property->getValue($connection);

		self::assertStringContainsString('-cipher', $command);
		self::assertStringContainsString('PSK-AES128-CBC-SHA256', $command);
	}
}
