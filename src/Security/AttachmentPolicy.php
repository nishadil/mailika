<?php

declare(strict_types=1);

namespace Mailika\Security;

use Mailika\Config\Config;
use RuntimeException;

final readonly class AttachmentPolicy
{
    public function __construct(private Config $config)
    {
    }

    /**
     * @param array<string, mixed> $file
     * @return array{path:string,name:string,mime:string}
     */
    public function validateUpload(array $file): array
    {
        $size = (int) ($file['size'] ?? 0);
        if ($size > $this->config->int('mail.max_attachment_bytes')) {
            throw new RuntimeException('Attachment exceeds configured size limit.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Attachment upload is invalid.');
        }

        $filename = $this->safeFilename((string) ($file['name'] ?? 'attachment'));
        $mime = $this->detectMime($tmpName, (string) ($file['type'] ?? 'application/octet-stream'));

        return ['path' => $tmpName, 'name' => $filename, 'mime' => $mime];
    }

    /**
     * @param list<array<string, mixed>> $files
     * @return list<array{path:string,name:string,mime:string}>
     */
    public function validateUploads(array $files): array
    {
        $files = array_values(array_filter(
            $files,
            static fn (array $file): bool => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE,
        ));

        if ($files === []) {
            return [];
        }

        $maxAttachments = max(1, $this->config->int('mail.max_attachments', 10));
        if (count($files) > $maxAttachments) {
            throw new RuntimeException('Too many attachments for one message.');
        }

        $totalBytes = 0;
        foreach ($files as $file) {
            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Attachment upload failed.');
            }

            $totalBytes += max(0, (int) ($file['size'] ?? 0));
            if ($totalBytes > $this->config->int('mail.max_attachment_total_bytes', 52_428_800)) {
                throw new RuntimeException('Attachments exceed configured total size limit.');
            }
        }

        return array_map(fn (array $file): array => $this->validateUpload($file), $files);
    }

    public function safeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[^A-Za-z0-9._ -]/', '_', $filename) ?? 'attachment';
        $filename = trim($filename, " .\t\n\r\0\x0B");
        $filename = substr($filename, 0, 180);
        $filename = trim($filename, " .\t\n\r\0\x0B");

        return $filename === '' || $filename === '.' || $filename === '..' ? 'attachment' : $filename;
    }

    public function safeContentType(string $contentType): string
    {
        return preg_match('/^[A-Za-z0-9.+-]+\/[A-Za-z0-9.+-]+$/', $contentType) === 1
            ? strtolower($contentType)
            : 'application/octet-stream';
    }

    public function inlineContentTypeAllowed(string $contentType): bool
    {
        return in_array($this->safeContentType($contentType), [
            'image/avif',
            'image/gif',
            'image/jpeg',
            'image/png',
            'image/webp',
        ], true);
    }

    public function validateDownload(int $reportedBytes, int $actualBytes): void
    {
        $size = max(0, $reportedBytes, $actualBytes);
        if ($size > $this->config->int('mail.max_attachment_bytes')) {
            throw new RuntimeException('Attachment exceeds configured size limit.');
        }
    }

    public function downloadContentType(string $contentType, string $mode): string
    {
        $contentType = $this->safeContentType($contentType);
        if ($mode === 'inline' && $this->inlineContentTypeAllowed($contentType)) {
            return $contentType;
        }

        return in_array($contentType, [
            'application/ecmascript',
            'application/javascript',
            'application/xhtml+xml',
            'application/xml',
            'image/svg+xml',
            'text/ecmascript',
            'text/html',
            'text/javascript',
            'text/xml',
        ], true) ? 'application/octet-stream' : $contentType;
    }

    public function contentDisposition(string $mode, string $filename): string
    {
        $mode = $mode === 'inline' ? 'inline' : 'attachment';
        $safeFilename = addcslashes($this->safeFilename($filename), '"\\');

        return $mode . '; filename="' . $safeFilename . '"';
    }

    private function detectMime(string $path, string $fallback): string
    {
        $mime = $fallback;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    $mime = $detected;
                }
            }
        }

        return $this->safeContentType($mime);
    }
}
