<?php

function normalize_dispute_evidence(string $bytes): array
{
    if ($bytes === '' || strlen($bytes) > 2 * 1024 * 1024) {
        throw new DomainException('Evidence must be a JPEG or PNG image no larger than 2 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    $size = @getimagesizefromstring($bytes);
    if (!in_array($mime,['image/jpeg','image/png'],true) || !$size || $size['mime'] !== $mime
        || $size[0] < 1 || $size[1] < 1 || $size[0] > 4096 || $size[1] > 4096
        || $size[0] * $size[1] > 8_000_000) {
        throw new DomainException('Use a valid JPEG or PNG within the supported image dimensions.');
    }
    if (!function_exists('imagecreatefromstring')) throw new DomainException('Evidence uploads are temporarily unavailable.');
    $image = @imagecreatefromstring($bytes);
    if ($image === false) throw new DomainException('The image could not be decoded.');
    if ($mime === 'image/png') imagesavealpha($image,true);
    ob_start();
    try {
        $written = $mime === 'image/png' ? imagepng($image) : imagejpeg($image,null,88);
        $output = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    if (!$written || $output === false || strlen($output) > 2 * 1024 * 1024) {
        throw new DomainException('The processed image exceeds 2 MB. Resize it and try again.');
    }
    return ['type'=>$mime,'bytes'=>$output];
}

function uploaded_dispute_evidence(?array $file): ?array
{
    if (!$file || ($file['error'] ?? null) === UPLOAD_ERR_NO_FILE) return null;
    $path = $file['tmp_name'] ?? null;
    if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($path) || !is_uploaded_file($path)
        || ($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new DomainException('The upload failed. Choose an image no larger than 2 MB.');
    }
    return normalize_dispute_evidence(file_get_contents($path,false,null,0,2 * 1024 * 1024 + 1) ?: '');
}

