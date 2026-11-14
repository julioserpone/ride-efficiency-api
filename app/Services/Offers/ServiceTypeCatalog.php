<?php

namespace App\Services\Offers;

/**
 * Canonical platform / service type / availability codes for ride offers.
 *
 * The catalogue lives in `config/ride_offer_platforms.php` so that adding a
 * service type is configuration work. This class only normalizes what OCR, the
 * mobile client or a manual request sends and resolves it to a canonical code.
 */
class ServiceTypeCatalog
{
    private readonly string $version;

    /** @var array<string, mixed> */
    private readonly array $platforms;

    /** @var array<string, mixed> */
    private readonly array $availabilityTypes;

    /** @var array<string, mixed> */
    private readonly array $nonOfferScreens;

    /**
     * @param  array<string, mixed>  $platforms
     * @param  array<string, mixed>  $availabilityTypes
     * @param  array<string, mixed>  $nonOfferScreens
     */
    public function __construct(
        string $version,
        array $platforms,
        array $availabilityTypes,
        array $nonOfferScreens,
    ) {
        $this->version = $version;
        $this->platforms = $platforms;
        $this->availabilityTypes = $availabilityTypes;
        $this->nonOfferScreens = $nonOfferScreens;
    }

    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $platforms */
        $platforms = config('ride_offer_platforms.platforms', []);
        /** @var array<string, mixed> $availabilityTypes */
        $availabilityTypes = config('ride_offer_platforms.availability_types', []);
        /** @var array<string, mixed> $nonOfferScreens */
        $nonOfferScreens = config('ride_offer_platforms.non_offer_screens', []);

        return new self(
            (string) config('ride_offer_platforms.version', '1'),
            $platforms,
            $availabilityTypes,
            $nonOfferScreens,
        );
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return list<string> */
    public function platformCodes(): array
    {
        return array_map('strval', array_keys($this->platforms));
    }

    /** @return list<string> */
    public function serviceTypeCodes(?string $platform = null): array
    {
        if ($platform === null) {
            $codes = [];

            foreach ($this->platforms as $definition) {
                foreach (array_keys($definition['service_types'] ?? []) as $serviceType) {
                    $codes[(string) $serviceType] = true;
                }
            }

            return array_keys($codes);
        }

        $code = $this->normalizePlatform($platform);

        if ($code === null) {
            return [];
        }

        return array_map('strval', array_keys($this->platforms[$code]['service_types'] ?? []));
    }

    /** @return list<string> */
    public function availabilityTypeCodes(): array
    {
        return array_map('strval', array_keys($this->availabilityTypes));
    }

    /**
     * Normalize free text ("Uber", "DiDi ", "inDrive") to a canonical platform
     * code, or to `null` when it is not part of the catalogue.
     */
    public function normalizePlatform(?string $value): ?string
    {
        return $this->resolve($value, $this->platforms);
    }

    /**
     * Normalize a service type for a platform ("UberX Priority" -> `uberx_priority`).
     * Returns `null` when the platform is unknown or the type is not catalogued;
     * callers must never invent a service type.
     */
    public function normalizeServiceType(?string $value, ?string $platform = null): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        // An explicit platform is authoritative: a `DiDi Taxi` label must never
        // resolve to Uber's `taxi`.
        if ($platform !== null && ($platformCode = $this->normalizePlatform($platform)) !== null) {
            return $this->resolve($value, $this->platforms[$platformCode]['service_types'] ?? []);
        }

        // Without a platform, respect a brand prefix in the label: strip the
        // brand ("inDrive Intercity" -> "Intercity") and resolve the rest.
        $normalized = $this->normalizeText($value);

        foreach ($this->platforms as $definitions) {
            foreach ($definitions['aliases'] ?? [] as $alias) {
                if (! \is_string($alias)) {
                    continue;
                }

                $normalizedAlias = $this->normalizeText($alias);

                if ($normalizedAlias === '' || ! $this->startsAtBoundary($normalized, $normalizedAlias)) {
                    continue;
                }

                $remainder = trim(mb_substr($normalized, mb_strlen($normalizedAlias)));

                if ($remainder === '') {
                    continue;
                }

                $resolved = $this->resolve($remainder, $definitions['service_types'] ?? []);

                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        foreach ($this->platforms as $definitions) {
            $resolved = $this->resolve($value, $definitions['service_types'] ?? []);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /** Normalize an availability label ("Exclusivo" -> `exclusive`). */
    public function normalizeAvailabilityType(?string $value): ?string
    {
        return $this->resolve($value, $this->availabilityTypes);
    }

    public function isValidPlatform(string $platform): bool
    {
        return \array_key_exists($platform, $this->platforms);
    }

    public function isValidServiceType(string $platform, string $serviceType): bool
    {
        return \array_key_exists($serviceType, $this->platforms[$platform]['service_types'] ?? []);
    }

    public function isValidAvailabilityType(string $availabilityType): bool
    {
        return \array_key_exists($availabilityType, $this->availabilityTypes);
    }

    /**
     * True when the recognized text looks like a platform list/summary screen
     * instead of an active offer.
     */
    public function isNonOfferScreen(string $platform, string $text): bool
    {
        $haystack = $this->normalizeText($text);

        foreach ($this->nonOfferScreens[$platform] ?? [] as $marker) {
            if (str_contains($haystack, $this->normalizeText($marker))) {
                return true;
            }
        }

        return false;
    }

    /** Resolve a label or alias against a set of definitions. */
    private function resolve(?string $value, mixed $definitions): ?string
    {
        if ($value === null || ! is_array($definitions)) {
            return null;
        }

        $needle = $this->normalizeText($value);

        if ($needle === '') {
            return null;
        }

        // Exact match first (code or alias), so a specific label such as
        // "UberX Priority" wins over the generic "UberX".
        /** @var array<string, mixed> $definitions */
        foreach ($definitions as $code => $definition) {
            $aliases = \is_array($definition) ? ($definition['aliases'] ?? []) : [];

            foreach ([$code, ...$aliases] as $candidate) {
                if (! \is_string($candidate) || $this->normalizeText($candidate) !== $needle) {
                    continue;
                }

                return $code;
            }
        }

        // Then a whole-word prefix: OCR prefixes the brand ("Uber Comfort") or
        // the label carries a suffix ("Express Nuevo" for `express`). Longest
        // alias wins, and the match never happens inside an unrelated word.
        $match = null;
        $matchLength = 0;

        foreach ($definitions as $code => $definition) {
            $aliases = \is_array($definition) ? ($definition['aliases'] ?? []) : [];

            foreach ($aliases as $alias) {
                if (! \is_string($alias)) {
                    continue;
                }

                $normalized = $this->normalizeText($alias);

                if ($normalized === '' || ! $this->startsAtBoundary($needle, $normalized)) {
                    continue;
                }

                if (mb_strlen($normalized) > $matchLength) {
                    $match = $code;
                    $matchLength = mb_strlen($normalized);
                }
            }
        }

        return $match;
    }

    /** True when `$alias` is a whole-word prefix of `$value`. */
    private function startsAtBoundary(string $value, string $alias): bool
    {
        if (! str_starts_with($value, $alias)) {
            return false;
        }

        $next = mb_substr($value, mb_strlen($alias), 1);

        return $next === '' || $next === ' ';
    }

    /** Lowercase, strip accents/punctuation and collapse whitespace. */
    private function normalizeText(string $value): string
    {
        $value = strtr(mb_strtolower(trim($value)), [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ñ' => 'n',
        ]);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
