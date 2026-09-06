<?php

namespace Tests\Unit;

use Gex023025Configuration;
use Gex023025ConfigurationFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../docs/operations/gex-023-025-configure.php';

class Gex023025ConfigurationTest extends TestCase
{
    public function test_no_arguments_only_print_usage_without_application_bootstrap(): void
    {
        ob_start();
        try {
            $this->assertSame(0, Gex023025Configuration::run([]));
            $this->assertSame(Gex023025Configuration::USAGE, ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }

    public static function modes(): array
    {
        return [
            [['universe-on'], ['GEX_EXPIRATION_UNIVERSE_ENABLED' => true, 'GEX_EXPIRATION_SHADOW_ENABLED' => false]],
            [['universe-off'], ['GEX_EXPIRATION_UNIVERSE_ENABLED' => false]],
            [['tracking-on', '--writers-drained'], ['EOD_SNAPSHOT_HEALTH_ENABLED' => true, 'EOD_SNAPSHOT_HEALTH_READ_ENABLED' => false]],
            [['reads-on'], ['EOD_SNAPSHOT_HEALTH_READ_ENABLED' => true]],
            [['reads-off'], ['EOD_SNAPSHOT_HEALTH_READ_ENABLED' => false]],
        ];
    }

    #[DataProvider('modes')]
    public function test_modes_change_only_their_named_flags(array $arguments, array $expected): void
    {
        $this->assertSame($expected, Gex023025Configuration::changes($arguments));
        $original = "# keep exactly\r\nUNRELATED='value with spaces'\r\nEOD_SNAPSHOT_HEALTH_ENABLED=true\r\n";
        $updated = Gex023025Configuration::updated($original, $expected);
        $this->assertStringStartsWith("# keep exactly\r\nUNRELATED='value with spaces'\r\n", $updated);
        $this->assertStringContainsString("EOD_SNAPSHOT_HEALTH_ENABLED=true\r\n", $updated);
        $this->assertSame($updated, Gex023025Configuration::updated($updated, $expected));
    }

    public static function invalidArguments(): array
    {
        return [[[]], [['tracking-on']], [['tracking-on', '--writers-drained', '--force']],
            [['reads-on', '--writers-drained']], [['enable']], [['reads-on', '--skip-checks']]];
    }

    #[DataProvider('invalidArguments')]
    public function test_missing_attestation_and_unknown_arguments_fail_closed(array $arguments): void
    {
        $this->expectException(Gex023025ConfigurationFailure::class);
        Gex023025Configuration::changes($arguments);
    }

    public function test_duplicates_and_quoted_export_keys_are_normalized_without_touching_multiline_values(): void
    {
        $original = "# keep\r\nEXAMPLE=\"first\r\nGEX_EXPIRATION_UNIVERSE_ENABLED=false\r\nlast\"\r\n"
            ."export 'GEX_EXPIRATION_UNIVERSE_ENABLED' = false # old\r\n"
            ."GEX_EXPIRATION_UNIVERSE_ENABLED=false\r\nTAIL=unchanged";
        $expected = "# keep\r\nEXAMPLE=\"first\r\nGEX_EXPIRATION_UNIVERSE_ENABLED=false\r\nlast\"\r\n"
            ."GEX_EXPIRATION_UNIVERSE_ENABLED=true\r\n\r\nTAIL=unchanged\r\nGEX_EXPIRATION_SHADOW_ENABLED=false\r\n";
        $this->assertSame($expected, Gex023025Configuration::updated($original, Gex023025Configuration::changes(['universe-on'])));
    }

    public function test_read_rollback_preserves_tracking_shadow_and_other_flag_bytes(): void
    {
        $original = "EOD_SNAPSHOT_HEALTH_ENABLED = true\nGEX_EXPIRATION_SHADOW_ENABLED=true\nEOD_SNAPSHOT_HEALTH_READ_ENABLED=true\n\n";
        $this->assertSame(str_replace('READ_ENABLED=true', 'READ_ENABLED=false', $original),
            Gex023025Configuration::updated($original, Gex023025Configuration::changes(['reads-off'])));
    }

    public function test_conflicting_duplicate_or_interpolated_prerequisites_are_not_trusted(): void
    {
        $key = 'EOD_SNAPSHOT_HEALTH_ENABLED';
        $this->assertTrue(Gex023025Configuration::configuredTrue($key.'="true"', $key));
        $this->assertTrue(Gex023025Configuration::configuredTrue($key."=true\n".$key.'=(true)', $key));
        $this->assertFalse(Gex023025Configuration::configuredTrue($key."=true\n".$key.'=false', $key));
        $this->assertFalse(Gex023025Configuration::configuredTrue($key.'=${ANOTHER_FLAG}', $key));
        $this->assertFalse(Gex023025Configuration::configuredTrue('', $key));
    }

    public function test_parser_failure_does_not_echo_the_value(): void
    {
        try {
            Gex023025Configuration::updated('KEY=private value with invalid spaces', Gex023025Configuration::changes(['reads-off']));
            $this->fail('An invalid file must not be rewritten.');
        } catch (Gex023025ConfigurationFailure $exception) {
            $this->assertStringNotContainsString('private', $exception->getMessage());
            $this->assertStringContainsString('no flags were changed', $exception->getMessage());
        }
    }

    public function test_arbitrary_variable_sets_are_rejected(): void
    {
        $this->expectException(Gex023025ConfigurationFailure::class);
        Gex023025Configuration::updated('APP_ENV=production', ['APP_ENV' => false]);
    }

    public function test_resolved_paths_must_belong_to_the_same_exact_allowlisted_site(): void
    {
        $web = '/home/forge/gexoptions.com';
        $worker = '/home/forge/stocks-options-ss7u2nu2.on-forge.com';
        $this->assertTrue(Gex023025Configuration::allowedPaths($web.'/releases/123', $web.'/.env'));
        $this->assertTrue(Gex023025Configuration::allowedPaths($worker.'/releases/123', $worker.'/shared/.env'));
        $this->assertFalse(Gex023025Configuration::allowedPaths($web.'/releases/123', $worker.'/.env'));
        $this->assertFalse(Gex023025Configuration::allowedPaths($web.'/releases/123', $web.'-other/.env'));
        $this->assertFalse(Gex023025Configuration::allowedPaths($web.'/releases/123', $web.'/../../.env'));
        $this->assertFalse(Gex023025Configuration::allowedPaths($web.'/releases/123', $web.'/secret.txt'));
        $this->assertFalse(Gex023025Configuration::allowedPaths('C:/workspace', 'C:/workspace/.env'));
    }

    public function test_only_clean_populated_manifests_are_usable_without_requiring_every_expiration(): void
    {
        $manifest = ['dirty' => false, 'expiration_count' => 2, 'expirations' => [
            ['selected_date' => null, 'selected_row_count' => 0],
            ['selected_date' => '2026-09-04', 'selected_row_count' => 40],
        ]];
        $this->assertTrue(Gex023025Configuration::usable($manifest));
        $this->assertFalse(Gex023025Configuration::usable(null));
        $this->assertFalse(Gex023025Configuration::usable(array_replace($manifest, ['dirty' => true])));
        $this->assertFalse(Gex023025Configuration::usable(array_replace($manifest, ['expiration_count' => 0])));
        $this->assertFalse(Gex023025Configuration::usable(array_replace($manifest, ['expirations' => [['selected_date' => null, 'selected_row_count' => 0]]])));
    }
}
