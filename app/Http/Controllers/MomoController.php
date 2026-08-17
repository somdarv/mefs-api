<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MomoNetwork;
use App\Http\Responses\ApiResponse;
use App\Rules\PhoneNumber;
use App\Services\Payments\PaystackClient;
use App\Support\GhanaPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Whose wallet is this?" — asked before any money is.
 *
 * ⚠️ THIS IS THE ONLY HONEST NETWORK DETECTION IN THE SYSTEM. `MomoNetwork::forPhone()` reads
 * a prefix table, and Ghana has had number portability since 2011, so a prefix says who
 * ISSUED a number and not who carries it today. Resolving a number against a network either
 * comes back with that network's registered account name or it does not — an answer from the
 * network itself rather than a guess about it. When the prefix guess resolves, the guess was
 * right; when it does not, trying the other two finds the network the number actually lives
 * on.
 *
 * ⚠️ AND IT IS AN ENHANCEMENT, NEVER A GATE. Law 7: a check that cannot be evaluated must not
 * block a sale. No keys, gateway down, an unrecognised response shape — every one of them
 * comes back `unresolved`, the checkout screen falls through to the manual network picker
 * that has always been there, and the customer pays. The worst outcome of this endpoint
 * failing is the experience the product had before it existed.
 *
 * Public and unauthenticated, because checkout is: a guest has no account and no order yet.
 * Rate limited at the route, because it takes a phone number and answers with a name.
 */
final class MomoController extends Controller
{
    public function __construct(private readonly PaystackClient $paystack) {}

    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', new PhoneNumber],
            // Optional: when the customer has already corrected the picker, that answer is
            // tried first rather than being re-litigated by the prefix table.
            'network' => ['nullable', Rule::enum(MomoNetwork::class)],
        ]);

        $phone = GhanaPhone::normalise($data['phone']);

        if ($phone === null) {
            return $this->unresolved('unreadable_number');
        }

        if (! $this->paystack->isConfigured()) {
            return $this->unresolved('not_configured');
        }

        /*
         * The guess first, then the rest. Ordering matters for cost, not correctness: most
         * numbers are still on the network that issued them, so the prefix guess resolves on
         * the first call almost every time and the other two are never made.
         */
        // `?? null`, not `!== null`: `validate()` returns only the keys that were sent, so a
        // request omitting `network` entirely has no such index at all.
        $stated = $data['network'] ?? null;

        $guess = $stated !== null
            ? MomoNetwork::from($stated)
            : MomoNetwork::forPhone($phone);

        $order = $guess === null
            ? MomoNetwork::all()
            : array_merge([$guess], array_values(array_filter(
                MomoNetwork::all(),
                static fn (MomoNetwork $network) => $network !== $guess,
            )));

        foreach ($order as $network) {
            $result = $this->paystack->resolveMomo($phone, $network->bankCode());

            if (($result['ok'] ?? false) !== true) {
                continue;
            }

            $name = $result['data']['account_name'] ?? null;

            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            return ApiResponse::success([
                'resolved' => true,
                'network' => $network->value,
                'network_label' => $network->label(),
                'account_name' => trim($name),
                // Whether the prefix table would have got there on its own. Not shown to
                // anyone — it is the only way to find out how wrong that table actually is,
                // and the answer decides whether the picker can ever be dropped.
                'matched_guess' => $guess === $network,
            ], 'Wallet found');
        }

        return $this->unresolved('no_match');
    }

    /**
     * ⚠️ A 200, NOT AN ERROR. "We could not check" is a normal answer here and the screen has
     * a perfectly good path for it. An error status would push the storefront's generic
     * failure handling in front of a customer whose number is very probably fine.
     */
    private function unresolved(string $reason): JsonResponse
    {
        return ApiResponse::success([
            'resolved' => false,
            'reason' => $reason,
            'network' => null,
            'network_label' => null,
            'account_name' => null,
        ], 'We could not check that number.');
    }
}
