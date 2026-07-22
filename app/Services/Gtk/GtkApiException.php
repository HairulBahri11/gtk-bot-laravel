<?php

namespace App\Services\Gtk;

use RuntimeException;

/**
 * Merepresentasikan response gagal dari API GTK (kode 201/401/404/409)
 * sesuai §5.2 / §7.8 PRD.
 */
class GtkApiException extends RuntimeException
{
    public function __construct(string $message, protected int $gtkCode)
    {
        parent::__construct($message, $gtkCode);
    }

    public function gtkCode(): int
    {
        return $this->gtkCode;
    }

    public function isNotFound(): bool
    {
        return $this->gtkCode === 404;
    }

    public function isDuplicate(): bool
    {
        return $this->gtkCode === 409;
    }

    public function isValidationError(): bool
    {
        return $this->gtkCode === 201;
    }

    public function isSystemError(): bool
    {
        return $this->gtkCode === 401;
    }
}
