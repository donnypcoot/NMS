<?php

declare(strict_types=1);

namespace Transdata\Nms;

final class CryptoService
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private string $key;

    public function __construct(string $encryptionKey)
    {
        if ($encryptionKey === '') {
            throw new \InvalidArgumentException('Encryption key tidak boleh kosong.');
        }

        // Normalisasi key menjadi 32 byte untuk AES-256.
        $this->key = hash('sha256', $encryptionKey, true);
    }

    /**
     * Enkripsi plaintext menggunakan AES-256-GCM.
     *
     * Format hasil:
     * base64( IV + TAG + CIPHERTEXT )
     */
    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Gagal mengenkripsi data.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Dekripsi data yang sebelumnya dibuat oleh encrypt().
     */
    public function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }

        $decoded = base64_decode($encrypted, true);

        if ($decoded === false) {
            throw new \RuntimeException('Format data terenkripsi tidak valid.');
        }

        $minimumLength = self::IV_LENGTH + self::TAG_LENGTH;

        if (strlen($decoded) <= $minimumLength) {
            throw new \RuntimeException('Data terenkripsi terlalu pendek.');
        }

        $iv = substr($decoded, 0, self::IV_LENGTH);
        $tag = substr($decoded, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($decoded, $minimumLength);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            ''
        );

        if ($plaintext === false) {
            throw new \RuntimeException(
                'Gagal mendekripsi data. Encryption key mungkin berbeda atau data rusak.'
            );
        }

        return $plaintext;
    }
}
