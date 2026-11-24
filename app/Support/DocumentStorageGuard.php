<?php

namespace App\Support;

/**
 * #29: private documents (expense receipts) must be on storage that survives a redeploy, and
 * never inside the public web tree. On Railway only the mounted volume persists
 * (RAILWAY_VOLUME_MOUNT_PATH), so the "documents" root must be inside it. Outside Railway
 * (local, CI, Docker rehearsals) only the public-tree rule applies.
 *
 * problem() returns null when the storage is acceptable, otherwise a reason. Callers fail
 * closed: no receipt is accepted and none is moved off the public volume until it is fixed.
 */
class DocumentStorageGuard
{
    public function problem(): ?string
    {
        $root = $this->normalize((string) config('filesystems.disks.documents.root'));

        foreach ([(string) config('filesystems.disks.public.root'), public_path()] as $public) {
            $public = $this->normalize($public);
            if ($this->within($root, $public) || $this->within($public, $root)) {
                return "Private documents root [{$root}] is inside the public web tree.";
            }
        }

        // The backups must cover the documents root, or a "durable" receipt is still unrecoverable.
        $covered = collect((array) config('backup.backup.source.files.include'))
            ->contains(fn ($include) => $this->within($root, $this->normalize((string) $include)));
        if (! $covered) {
            return "Private documents root [{$root}] is not included in the backups.";
        }

        if ((string) getenv('RAILWAY_ENVIRONMENT_NAME') === '') {
            return null;
        }
        $mount = (string) getenv('RAILWAY_VOLUME_MOUNT_PATH');
        if ($mount === '') {
            return "Private documents root [{$root}]: this Railway service has no persistent volume mounted.";
        }
        $mount = $this->normalize($mount);

        return $this->within($root, $mount) ? null
            : "Private documents root [{$root}] is outside the persistent volume [{$mount}]: files would be lost on redeploy.";
    }

    private function within(string $path, string $base): bool
    {
        return $path === $base || str_starts_with($path, rtrim($base, '/').'/');
    }

    /** Absolute path with "." and ".." resolved, without requiring it to exist. */
    private function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            $segment === '..' ? array_pop($parts) : $parts[] = $segment;
        }

        return '/'.implode('/', $parts);
    }
}
