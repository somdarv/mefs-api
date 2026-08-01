<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What actually landed in her bank.
 *
 * Paystack pays out in batches: one settlement covers many transactions, minus their fees.
 * Until a settlement is recorded, `payments.settled_amount` is **null — which means unknown,
 * not zero**, and that distinction is the whole reason the money screen can be trusted.
 *
 * ── WHY A CSV AND NOT THEIR API ───────────────────────────────────────────────
 *
 * A settlement export she downloads herself is a smaller thing to build, a smaller thing to
 * trust, and — decisively — a thing that can be exercised today. Polling Paystack's
 * settlement API needs live credentials this build does not have, so it could be written but
 * not proven, and an unproven money integration is worse than none. Swapping to polling
 * later means writing a second producer of `SettlementRow`s; nothing else changes.
 *
 * ⚠️ THE COLUMN MAPPING HAS NEVER MET A REAL PAYSTACK EXPORT.
 *
 * Same posture as `SmsOnlineGhSender`: the aliases below are a guess from their docs. If a
 * real export disagrees, `HEADERS` is the only thing to change — deliberately, rather than
 * sniffing at columns and picking whichever looks closest. Guessing at which column holds
 * money is how the wrong column gets imported and nobody notices for a quarter.
 *
 * ── THE RULE ON PARTIAL IMPORTS ───────────────────────────────────────────────
 *
 * A structural problem — a missing header, an unparseable amount — **refuses the whole
 * file**, because it means the wrong file or the wrong mapping and importing 197 of 200 rows
 * leaves her to work out which three. A per-row mismatch is reported and skipped, because a
 * batch legitimately contains references this system has never seen.
 */
final class SettlementImporter
{
    /**
     * Accepted spellings, canonical name first. Explicit, and short on purpose.
     *
     * @var array<string, list<string>>
     */
    private const HEADERS = [
        'reference' => ['reference', 'transaction_reference', 'txn_reference'],
        'settled_amount' => ['settled_amount', 'amount_settled', 'net', 'net_amount'],
        'settled_at' => ['settled_at', 'settlement_date', 'settled_on', 'date'],
    ];

    /**
     * @param  list<array<string, string>>  $rows  parsed CSV rows, keyed by header
     * @return array{summary: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function import(array $rows): array
    {
        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => ['That file has no rows in it.'],
            ]);
        }

        $columns = $this->resolveColumns(array_keys($rows[0]));

        /*
         * ⚠️ READ THE WHOLE FILE BEFORE WRITING ANY OF IT — TWO PASSES, NOT ONE.
         *
         * Structural failures are thrown, and a throw on row 40 of a single-pass loop leaves
         * rows 1–39 already written: precisely the half-imported file the rule at the top of
         * this class refuses to produce. Validating everything first means the refusal
         * happens before the first `save()`.
         *
         * The transaction below is the second belt — it covers a database-level failure
         * partway through, which no amount of up-front parsing can rule out.
         */
        $parsed = [];

        foreach ($rows as $index => $row) {
            $parsed[] = $this->parseRow($row, $columns, $index);
        }

        $results = DB::transaction(fn () => array_map($this->applyRow(...), $parsed));

        $summary = [];

        foreach ($results as $result) {
            $summary[$result->outcome] = ($summary[$result->outcome] ?? 0) + 1;
        }

        return [
            'summary' => $summary,
            'rows' => array_map(fn (SettlementRow $result) => $result->toArray(), $results),
        ];
    }

    /**
     * Map the file's headers onto ours, or refuse.
     *
     * The refusal names what it found. "Missing column: settled_amount" with no list of the
     * file's own headers is a message that sends her back to a spreadsheet to guess.
     *
     * @param  list<string>  $headers
     * @return array<string, string>
     */
    private function resolveColumns(array $headers): array
    {
        $normalised = [];

        foreach ($headers as $header) {
            $normalised[$this->normalise($header)] = $header;
        }

        $columns = [];

        foreach (self::HEADERS as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if (isset($normalised[$alias])) {
                    $columns[$canonical] = $normalised[$alias];
                    break;
                }
            }

            if (! isset($columns[$canonical])) {
                throw ValidationException::withMessages([
                    'file' => [sprintf(
                        'No %s column. Expected one of: %s. The file has: %s.',
                        $canonical,
                        implode(', ', $aliases),
                        implode(', ', $headers),
                    )],
                ]);
            }
        }

        return $columns;
    }

    /**
     * Pass one: everything that can refuse the file. Touches nothing.
     *
     * @param  array<string, string>  $columns
     * @return array{reference: string, amount: int, settled_at: CarbonImmutable}
     */
    private function parseRow(array $row, array $columns, int $index): array
    {
        $reference = trim($row[$columns['reference']] ?? '');

        if ($reference === '') {
            throw ValidationException::withMessages([
                'file' => ["Row {$index} has no reference. That is a malformed file, not a missing payment."],
            ]);
        }

        return [
            'reference' => $reference,
            'amount' => $this->toPesewas($row[$columns['settled_amount']] ?? '', $index),
            'settled_at' => $this->toDate($row[$columns['settled_at']] ?? '', $index),
        ];
    }

    /**
     * Pass two: the writes, and the per-row outcomes that are reported rather than fatal.
     *
     * @param  array{reference: string, amount: int, settled_at: CarbonImmutable}  $row
     */
    private function applyRow(array $row): SettlementRow
    {
        ['reference' => $reference, 'amount' => $amount, 'settled_at' => $settledAt] = $row;

        $payment = Payment::query()->where('reference', $reference)->first();

        if ($payment === null) {
            return SettlementRow::unknown($reference);
        }

        // A payout is never larger than the charge — fees only ever come off. This is the
        // wrong file, the wrong column, or the wrong units.
        if ($amount > $payment->amount) {
            return SettlementRow::implausible($reference, $payment->amount, $amount);
        }

        if ($payment->settled_amount !== null) {
            return $payment->settled_amount === $amount
                ? SettlementRow::unchanged($reference, $amount)
                : SettlementRow::conflict($reference, $payment->settled_amount, $amount);
        }

        $payment->forceFill([
            'settled_amount' => $amount,
            'settled_at' => $settledAt,
        ])->save();

        return SettlementRow::settled($reference, $amount);
    }

    /**
     * "12.50" → 1250.
     *
     * ⚠️ THE ONE CONVERSION BOUNDARY ON THIS PATH. Everything downstream is integer pesewas;
     * a float that reaches the database is a rounding error in somebody's takings.
     *
     * Refuses anything that is not a plain number with at most two decimals — including
     * "GHS 12.50" and "1,250.00". Stripping a currency symbol or a thousands separator means
     * guessing at the locale, and guessing wrong turns 1,250 into 1250 pesewas or into
     * 1,250 cedis with no way to tell which happened afterwards.
     */
    private function toPesewas(string $raw, int $index): int
    {
        $value = trim($raw);

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages([
                'file' => [sprintf(
                    'Row %d has "%s" where a settled amount should be. Expected a plain '.
                    'number in cedis like 12.50 — no currency symbol, no thousands separator.',
                    $index,
                    $value,
                )],
            ]);
        }

        // Round after multiplying: 12.50 is not exactly representable, and (int) truncates
        // 1249.9999999 to 1249. One pesewa lost per row is exactly the kind of error a
        // reconciliation screen exists to catch, so it must not be introduced by the
        // reconciliation itself.
        return (int) round((float) $value * 100);
    }

    private function toDate(string $raw, int $index): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse(trim($raw));
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'file' => ["Row {$index} has \"{$raw}\" where a settlement date should be."],
            ]);
        }
    }

    private function normalise(string $header): string
    {
        return strtolower(trim(str_replace([' ', '-'], '_', $header)));
    }

    /**
     * Parse an uploaded CSV into rows keyed by header.
     *
     * `str_getcsv` per line rather than a library: this is one file format read in one place,
     * and a dependency for it is a dependency to keep updated forever.
     *
     * @return list<array<string, string>>
     */
    public static function parse(string $contents): array
    {
        // Strip a UTF-8 BOM. Excel writes one, and it silently becomes part of the first
        // header — so "reference" arrives as "\u{FEFF}reference" and matches nothing, and
        // the error message says the column is missing while it is plainly there.
        $contents = preg_replace('/^\x{FEFF}/u', '', $contents) ?? $contents;

        $lines = preg_split('/\r\n|\r|\n/', trim($contents)) ?: [];

        if (count($lines) < 2) {
            return [];
        }

        $headers = str_getcsv(array_shift($lines), ',', '"', '\\');
        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = str_getcsv($line, ',', '"', '\\');
            $row = [];

            foreach ($headers as $position => $header) {
                $row[(string) $header] = (string) ($cells[$position] ?? '');
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
