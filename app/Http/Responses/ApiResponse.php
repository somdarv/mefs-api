<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * The response envelope, fixed on day one (brief §2.1).
 *
 *     { "success": bool, "message": string, "data": T, "errors"?: object }
 *
 * Every endpoint returns this shape, success or failure. The frontend's error handler reads
 * `message` and `errors` straight off the body and has nowhere else to look, so failure
 * paths are enveloped too — see the renderable handlers in bootstrap/app.php.
 *
 * ⚠️ THE RULE THAT COST THE ORIGINAL MONTHS
 *
 * `success()` takes (data, message) and NOTHING ELSE. There is deliberately no third
 * positional $status parameter. The original's helper accepted one, silently dropped it,
 * and returned 200 for every creation for months (brief trap §10.12). Adding one here
 * re-opens that hole, because the call site *looks* correct.
 *
 * To return another status, use the named helper. That is the whole point of them.
 */
final class ApiResponse
{
    /** 200. The default for every read and every successful mutation that isn't a create. */
    public static function success(mixed $data = null, string $message = 'OK'): JsonResponse
    {
        return self::envelope(true, $message, $data, null, 200);
    }

    /** 201. A resource now exists at a new identity. */
    public static function created(mixed $data = null, string $message = 'Created'): JsonResponse
    {
        return self::envelope(true, $message, $data, null, 201);
    }

    /** 202. Queued — the work has been accepted but has not happened yet. */
    public static function accepted(mixed $data = null, string $message = 'Accepted'): JsonResponse
    {
        return self::envelope(true, $message, $data, null, 202);
    }

    /**
     * A failure. `$status` is second and required so it can never be forgotten —
     * the inverse of the success() rule above, and for the same reason.
     *
     * `data` stays present and null rather than being omitted: a client destructuring the
     * envelope should never hit an undefined key on an error path.
     */
    public static function error(
        string $message,
        int $status,
        ?array $errors = null,
    ): JsonResponse {
        return self::envelope(false, $message, null, $errors, $status);
    }

    /** The single place the envelope's shape is written down. */
    private static function envelope(
        bool $success,
        string $message,
        mixed $data,
        ?array $errors,
        int $status,
    ): JsonResponse {
        $body = [
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ];

        // Present only on failure. An empty `errors` on a success response would read as
        // "there were errors, but none of them" to anyone checking for the key.
        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status);
    }
}
