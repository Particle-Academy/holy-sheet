<?php

declare(strict_types=1);

namespace HolySheet\Laravel\Http;

use HolySheet\Agent;
use HolySheet\Exceptions\SchemaException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generic HTTP entry for browser-side / agent-side xlsx generation.
 *
 * Apps opt-in by registering the route in their own RouteServiceProvider
 * or routes/web.php — Holy Sheet doesn't auto-register routes (keeps the
 * package's URL surface explicit / opt-in):
 *
 *   Route::post('/holy-sheet/export', HolySheetController::class);
 *
 * Request body — JSON:
 *   {
 *     "schema": { ...workbook schema... },
 *     "filename": "report.xlsx"   // optional, defaults to "workbook.xlsx"
 *   }
 *
 * Successful response: xlsx bytes with Content-Disposition: attachment.
 * Validation failure: 422 JSON with structured errors.
 */
final class HolySheetController
{
    public function __invoke(Request $request): Response
    {
        $schema = $request->input('schema');
        $filename = $this->safeFilename((string) $request->input('filename', 'workbook.xlsx'));

        if (!is_array($schema)) {
            return response()->json([
                'error' => 'invalid_request',
                'message' => 'Request body must include a "schema" key with the workbook definition.',
            ], 422);
        }

        try {
            $bytes = Agent::toBytes($schema);
        } catch (SchemaException $e) {
            return response()->json([
                'error' => 'validation',
                'message' => $e->getMessage(),
                'errors' => $e->getErrors(),
            ], 422);
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.addslashes($filename).'"',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'no-store',
        ]);
    }

    private function safeFilename(string $name): string
    {
        $name = preg_replace('/[\/\\\\?%*:|"<>]/', '_', $name) ?? 'workbook.xlsx';
        if (!str_ends_with(strtolower($name), '.xlsx')) {
            $name .= '.xlsx';
        }
        return $name;
    }
}
