<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Responses\ApiResponse;
use ReflectionMethod;
// Not PHPUnit's bare TestCase: ApiResponse builds its JsonResponse through the `response()`
// helper, which needs the container booted.
use Tests\TestCase;

/**
 * The envelope contract, pinned.
 *
 * Two of these tests exist because the failure they describe is SILENT — it produces a
 * plausible response rather than an error, which is the shape of every expensive bug in the
 * brief's §10.
 */
final class ApiResponseTest extends TestCase
{
    public function test_success_is_200_and_carries_data_and_message(): void
    {
        $response = ApiResponse::success(['id' => 1], 'Fetched');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'success' => true,
            'message' => 'Fetched',
            'data' => ['id' => 1],
        ], $response->getData(true));
    }

    public function test_created_is_201(): void
    {
        $this->assertSame(201, ApiResponse::created(['id' => 1])->getStatusCode());
    }

    public function test_accepted_is_202(): void
    {
        $this->assertSame(202, ApiResponse::accepted()->getStatusCode());
    }

    /**
     * ⚠️ THE ONE THAT MATTERS (brief trap §10.12).
     *
     * The original's success helper accepted a third positional argument, silently dropped
     * it, and returned 200 for every creation for months. Nobody noticed because the call
     * site read as correct: `success($data, 'Created', 201)`.
     *
     * This asserts on the SIGNATURE, not on behaviour, because behaviour cannot detect the
     * mistake — a dropped argument produces a valid 200 response, not an error. If someone
     * adds a `$status` parameter to success(), this fails and points at the trap.
     */
    public function test_success_has_no_status_parameter(): void
    {
        $parameters = (new ReflectionMethod(ApiResponse::class, 'success'))->getParameters();
        $names = array_map(fn ($p) => $p->getName(), $parameters);

        $this->assertSame(
            ['data', 'message'],
            $names,
            'ApiResponse::success() must take (data, message) and nothing else. A third '
            .'positional $status is brief trap §10.12: the original accepted one, dropped '
            .'it silently, and returned 200 for every creation for months. Use created(), '
            .'accepted() or error() instead.',
        );
    }

    /**
     * The inverse rule: on an error the status IS the point, so it is required and second.
     * A defaulted status would let a 500 ship as a 422.
     */
    public function test_error_requires_an_explicit_status(): void
    {
        $status = (new ReflectionMethod(ApiResponse::class, 'error'))->getParameters()[1];

        $this->assertSame('status', $status->getName());
        $this->assertFalse(
            $status->isOptional(),
            'ApiResponse::error() must require an explicit status — a default lets a 500 '
            .'ship as whatever the default happens to be.',
        );
    }

    public function test_error_carries_the_validation_bag_through(): void
    {
        $response = ApiResponse::error('The given data was invalid.', 422, [
            'contact_phone' => ['The contact phone format is invalid.'],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([
            'success' => false,
            'message' => 'The given data was invalid.',
            'data' => null,
            'errors' => ['contact_phone' => ['The contact phone format is invalid.']],
        ], $response->getData(true));
    }

    /**
     * `errors` is absent on success, not present-and-empty. An empty bag would read as
     * "there were errors, but none of them" to anyone checking for the key.
     */
    public function test_success_omits_the_errors_key_entirely(): void
    {
        $this->assertArrayNotHasKey('errors', ApiResponse::success([])->getData(true));
    }

    /**
     * `data` is present and null on failure rather than omitted, so a client destructuring
     * the envelope never hits an undefined key on the error path.
     */
    public function test_error_keeps_data_present_and_null(): void
    {
        $body = ApiResponse::error('Nope', 400)->getData(true);

        $this->assertArrayHasKey('data', $body);
        $this->assertNull($body['data']);
    }
}
