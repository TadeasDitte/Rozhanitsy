<?php

namespace App\Services;

use App\Models\CpeMap;
use App\Models\Product;
use App\Models\VulnerabilityRange;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @phpstan-type ResolvedCpe array{product_id: int|null, confidence: 'exact'|'fuzzy'|'unmatched'}
 */
class NvdCpeResolver
{
    private const FUZZY_THRESHOLD = 0.87;

    private const VENDOR_WEIGHT = 0.4;

    private const PRODUCT_WEIGHT = 0.6;

    /** @var Collection<int, Product>|null */
    private ?Collection $productCache = null;

    /** @var array<string, ResolvedCpe> */
    private array $resolvedCache = [];

    /**
     * @return ResolvedCpe
     */
    public function resolve(string $cpeCriteria): array
    {
        [$vendor, $product] = $this->parseCpe($cpeCriteria);

        $vendor = $vendor !== null ? Str::lower($vendor) : null;
        $product = $product !== null ? Str::lower($product) : null;

        if ($vendor === null || $product === null || $vendor === '' || $product === '' || $vendor === '*' || $product === '*') {
            return ['product_id' => null, 'confidence' => VulnerabilityRange::MATCH_UNMATCHED];
        }

        $key = $vendor.'|'.$product;

        if (isset($this->resolvedCache[$key])) {
            return $this->resolvedCache[$key];
        }

        return $this->resolvedCache[$key] = $this->lookup($vendor, $product);
    }

    public function flush(): void
    {
        $this->productCache = null;
        $this->resolvedCache = [];
    }

    /**
     * @return ResolvedCpe
     */
    private function lookup(string $vendor, string $product): array
    {
        $existing = CpeMap::query()
            ->where('cpe_vendor', $vendor)
            ->where('cpe_product', $product)
            ->first();

        if ($existing !== null) {
            return [
                'product_id' => $existing->product_id,
                'confidence' => $existing->match_type === CpeMap::TYPE_FUZZY
                    ? VulnerabilityRange::MATCH_FUZZY
                    : VulnerabilityRange::MATCH_EXACT,
            ];
        }

        $match = $this->fuzzyMatch($vendor, $product);

        if ($match === null) {
            return ['product_id' => null, 'confidence' => VulnerabilityRange::MATCH_UNMATCHED];
        }

        CpeMap::create([
            'cpe_vendor' => $vendor,
            'cpe_product' => $product,
            'product_id' => $match->id,
            'match_type' => CpeMap::TYPE_FUZZY,
        ]);

        return ['product_id' => $match->id, 'confidence' => VulnerabilityRange::MATCH_FUZZY];
    }

    /**
     * cpe:2.3:a:vendor:product:version:update:edition:lang:sw_edition:target_sw:target_hw:other
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function parseCpe(string $criteria): array
    {
        $parts = explode(':', $criteria);

        return [$parts[3] ?? null, $parts[4] ?? null];
    }

    private function fuzzyMatch(string $vendor, string $product): ?Product
    {
        $this->productCache ??= Product::with('vendor')->get();

        $best = null;
        $bestScore = 0.0;

        foreach ($this->productCache as $candidate) {
            $vendorSimilarity = $this->similarity($vendor, $candidate->vendor->slug);
            $productSimilarity = $this->similarity($product, $candidate->slug);
            $score = ($vendorSimilarity * self::VENDOR_WEIGHT) + ($productSimilarity * self::PRODUCT_WEIGHT);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $bestScore >= self::FUZZY_THRESHOLD ? $best : null;
    }

    private function similarity(string $first, string $second): float
    {
        $first = Str::slug($first);
        $second = Str::slug($second);

        if ($first === '' || $second === '') {
            return 0.0;
        }

        similar_text($first, $second, $percent);

        return $percent / 100;
    }
}
