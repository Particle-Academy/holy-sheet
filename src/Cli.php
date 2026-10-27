<?php

declare(strict_types=1);

namespace HolySheet;

use HolySheet\Archive\OfficeTemplate;
use HolySheet\Archive\SourceArchive;
use HolySheet\Exceptions\ArchiveRefused;
use HolySheet\Exceptions\OfficeTemplateRefused;
use HolySheet\Exceptions\UnsupportedFormatException;

/**
 * `vendor/bin/holy-sheet`, for hosts without Laravel (with Laravel the same
 * conversion is `php artisan holy-sheet:template-convert`). One command today:
 *
 *     holy-sheet template:convert <input> <output> [--to=document|template] [--force]
 *
 * Exit codes: 0 converted, 1 refused (the reason is printed), 2 usage.
 *
 * @internal
 */
final class Cli
{
    private const USAGE = "Usage: holy-sheet template:convert <input> <output> [--to=document|template] [--force]\n"
        ."  Converts an .xltx/.ots template to its .xlsx/.ods twin, or back.\n"
        ."  Without --to it flips whichever the input is.\n";

    /**
     * @param  list<string>  $args
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public static function run(array $args, $stdout, $stderr): int
    {
        $command = array_shift($args);
        $positional = [];
        $to = null;
        $force = false;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--to=')) {
                $to = substr($arg, 5);
            } elseif ($arg === '--force') {
                $force = true;
            } elseif (str_starts_with($arg, '--')) {
                fwrite($stderr, "Unknown option {$arg}\n".self::USAGE);

                return 2;
            } else {
                $positional[] = $arg;
            }
        }
        if ($command !== 'template:convert' || count($positional) !== 2) {
            fwrite($stderr, self::USAGE);

            return 2;
        }
        [$input, $output] = $positional;

        $result = self::convertFile($input, $output, $to, $force);
        fwrite($result[0] === 0 ? $stdout : $stderr, $result[1]."\n");

        return $result[0];
    }

    /**
     * Shared by the bin script and the artisan command.
     *
     * @return array{0: int, 1: string} exit code and the line to print
     */
    public static function convertFile(string $input, string $output, ?string $to, bool $force): array
    {
        if (file_exists($output) && ! $force) {
            return [1, "Refused: {$output} already exists (pass --force to replace it)."];
        }
        $real = realpath($input);
        if ($real !== false && $real === realpath($output)) {
            return [1, 'Refused: the output is the input.'];
        }
        if (! is_file($input)) {
            return [1, "Refused: {$input} is not a file."];
        }

        try {
            $converted = Agent::convertTemplate($input, $to);
        } catch (OfficeTemplateRefused|ArchiveRefused $error) {
            return [1, "Refused ({$error->reason}): {$error->getMessage()}"];
        } catch (UnsupportedFormatException $error) {
            return [1, "Refused (unsupported): {$error->getMessage()}"];
        }

        // Written beside the target and renamed, so a failure leaves no half file.
        $temp = $output.'.tmp-'.bin2hex(random_bytes(4));
        if (file_put_contents($temp, $converted) !== strlen($converted) || ! rename($temp, $output)) {
            @unlink($temp);

            return [1, "Could not write {$output}."];
        }
        $kind = OfficeTemplate::kind(SourceArchive::open($converted));

        return [0, "Wrote {$output} ({$kind})."];
    }
}
