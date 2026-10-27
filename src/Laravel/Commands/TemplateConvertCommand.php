<?php

declare(strict_types=1);

namespace HolySheet\Laravel\Commands;

use HolySheet\Cli;
use Illuminate\Console\Command;

/**
 * `php artisan holy-sheet:template-convert <input> <output> [--to=document|template] [--force]`
 *
 * The artisan face of `vendor/bin/holy-sheet template:convert`: an .xltx/.ots
 * template to its .xlsx/.ods twin, or back, rewriting only the declaration.
 * Exits 1 with the refusal reason on a macro-enabled or mismatched package.
 */
final class TemplateConvertCommand extends Command
{
    protected $signature = 'holy-sheet:template-convert
                            {input : The template or workbook to convert}
                            {output : Where to write the result}
                            {--to= : document or template (default: flip)}
                            {--force : Replace an existing output file}';

    protected $description = 'Convert an .xltx/.ots template to its .xlsx/.ods twin, or back';

    public function handle(): int
    {
        $to = $this->option('to');
        [$code, $line] = Cli::convertFile(
            (string) $this->argument('input'),
            (string) $this->argument('output'),
            is_string($to) && $to !== '' ? $to : null,
            (bool) $this->option('force'),
        );
        $code === 0 ? $this->line($line) : $this->error($line);

        return $code === 0 ? self::SUCCESS : self::FAILURE;
    }
}
