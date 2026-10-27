<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Result;

/**
 * Pieces of one size (and label) that could not be placed, with the reason.
 *
 * Immutable by convention – treat the public properties as read-only.
 */
final class UnplacedPieces
{
    /** The piece fits a registered format, but no sheet of it was left. */
    public const REASON_STOCK = 'stock';

    /** The piece is larger than every registered format (in the allowed orientations). */
    public const REASON_TOO_LARGE = 'too_large';

    private const MESSAGES = [
        self::REASON_STOCK     => 'Not enough stock.',
        self::REASON_TOO_LARGE => 'Larger than every stock format.',
    ];

    /** Czech messages reported by 1.x – kept for toLegacyArray(). */
    private const LEGACY_MESSAGES = [
        self::REASON_STOCK     => 'Nedostatek skladových zásob.',
        self::REASON_TOO_LARGE => 'Kus je větší než všechny dostupné formáty desek.',
    ];

    public function __construct(
        public float   $width,
        public float   $height,
        public int     $qty,
        public string  $reasonCode,
        public ?string $label = null,
    ) {
    }

    /** Human-readable (English) reason. */
    public function reason(): string
    {
        return self::MESSAGES[$this->reasonCode] ?? $this->reasonCode;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'width'       => $this->width,
            'height'      => $this->height,
            'qty'         => $this->qty,
            'reason_code' => $this->reasonCode,
            'reason'      => $this->reason(),
            'label'       => $this->label,
        ];
    }

    /** @return array{width: float, height: float, qty: int, reason: string} */
    public function toLegacyArray(): array
    {
        return [
            'width'  => $this->width,
            'height' => $this->height,
            'qty'    => $this->qty,
            'reason' => self::LEGACY_MESSAGES[$this->reasonCode] ?? $this->reasonCode,
        ];
    }
}
