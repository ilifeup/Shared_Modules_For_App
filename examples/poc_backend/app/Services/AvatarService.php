<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A03 avatar: the upload is decoded and re-encoded as a square JPEG (max 512 px),
 * which drops EXIF/GPS metadata and anything that is not a real image. Files live on
 * a private disk (AVATAR_DISK, default "local") and are served only to their owner.
 */
final class AvatarService
{
    public const SIZE = 512;

    public function disk(): string
    {
        return (string) config('filesystems.avatar_disk', 'local');
    }

    public function store(User $user, UploadedFile $file): void
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new ApiException(503, 'AVATAR_UNAVAILABLE', '目前無法處理頭像，請稍後再試。');
        }

        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($source === false) {
            throw new ApiException(422, 'VALIDATION_FAILED', '無法讀取圖片，請改用 JPG 或 PNG。', [
                'avatar' => ['無法讀取圖片，請改用 JPG 或 PNG。'],
            ]);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $target = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::SIZE, self::SIZE, $side, $side);

        ob_start();
        imagejpeg($target, null, 85);
        $jpeg = (string) ob_get_clean();
        imagedestroy($source);
        imagedestroy($target);

        $path = 'avatars/'.$user->id.'/'.Str::random(32).'.jpg';
        Storage::disk($this->disk())->put($path, $jpeg);

        $old = $user->avatar_path;
        $user->forceFill(['avatar_path' => $path])->save();

        if ($old !== null && $old !== $path) {
            Storage::disk($this->disk())->delete($old);
        }

        AuditEvent::record('member.avatar_updated', $user->id, $user);
    }

    public function delete(User $user): void
    {
        if ($user->avatar_path === null) {
            return;
        }

        Storage::disk($this->disk())->delete($user->avatar_path);
        $user->forceFill(['avatar_path' => null])->save();

        AuditEvent::record('member.avatar_deleted', $user->id, $user);
    }

    public function exists(User $user): bool
    {
        return $user->avatar_path !== null && Storage::disk($this->disk())->exists($user->avatar_path);
    }
}
