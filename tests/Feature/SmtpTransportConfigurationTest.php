<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

class SmtpTransportConfigurationTest extends TestCase
{
    public static function connectionModes(): array
    {
        return [
            'Mailtrap STARTTLS with legacy tls setting' => [587, 'tls', null, 'smtp', false],
            'implicit TLS on standard port' => [465, 'tls', null, 'smtps', true],
            'alternative STARTTLS port' => [2525, 'tls', null, 'smtp', false],
            'explicit nonstandard implicit TLS provider' => [2465, 'ssl', 'smtps', 'smtps', true],
        ];
    }

    #[DataProvider('connectionModes')]
    public function test_port_and_explicit_scheme_select_the_correct_transport_without_connecting(
        int $port, string $encryption, ?string $override, string $scheme, bool $implicitTls
    ): void {
        $values = ['MAIL_HOST' => 'smtp.example.test', 'MAIL_PORT' => (string) $port,
            'MAIL_ENCRYPTION' => $encryption, 'MAIL_SCHEME' => $override, 'MAIL_URL' => null];
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
                putenv($key.'='.$value);
            }
        }
        try {
            $mail = require config_path('mail.php');
            $config = $mail['mailers']['smtp'];
            // The explicit scheme is needed even on the installed older Laravel
            // release, whose encryption=tls fallback chooses SMTPS on port 587.
            $this->assertSame($scheme, $config['scheme']);
            $transport = Mail::build($config)->getSymfonyTransport();
            $this->assertInstanceOf(EsmtpTransport::class, $transport);
            $this->assertSame($port, $transport->getStream()->getPort());
            $this->assertSame($implicitTls, $transport->getStream()->isTLS());
            $this->assertTrue($transport->isAutoTls());
        } finally {
            foreach ($previous as $key => [$environment, $server, $process]) {
                if ($environment === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $environment;
                }
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
                putenv($process === false ? $key : $key.'='.$process);
            }
        }
    }
}
