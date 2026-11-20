<?php
namespace App\Services;

class GpxHashService
{
    public function hash(string $absolutePath): string
    {
        if (! is_file($absolutePath)) {
            throw new \InvalidArgumentException('File GPX tidak ditemukan untuk verifikasi hash.');
        }

        return hash_file('sha256', $absolutePath);
    }

    public function verify(string $absolutePath, string $expectedHash): array
    {
        $actualHash = $this->hash($absolutePath);
        $verified   = hash_equals($expectedHash, $actualHash);

        return [
            'actual_hash' => $actualHash,
            'status'      => $verified ? 'verified' : 'invalid',
            'message'     => $verified ? 'Hash file cocok.' : 'Hash file tidak cocok dengan versi tersimpan.',
        ];
    }
}
