<?php

namespace App\Services;

use RuntimeException;

class BirthdayWishImageComposer
{
    private const WIDTH = 1200;

    public function compose(string $sourcePath, string $message, string $messageAr = ''): string
    {
        $source = $this->openImage($sourcePath);
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $height = max(1, (int) round(self::WIDTH * $sourceHeight / $sourceWidth));

        $canvas = imagecreatetruecolor(self::WIDTH, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, self::WIDTH, $height, $sourceWidth, $sourceHeight);
        imagedestroy($source);

        $font = $this->fontPath();
        $fontSize = 34;
        $lineHeight = 50;
        $columnWidth = $messageAr !== '' ? 470 : self::WIDTH - 170;
        $lines = $this->wrap($message, $font, $fontSize, $columnWidth);
        $linesAr = $messageAr !== '' ? $this->wrap($messageAr, $font, $fontSize, $columnWidth) : [];
        if (max(count($lines), count($linesAr)) > 12) {
            $fontSize = 27;
            $lineHeight = 41;
            $lines = $this->wrap($message, $font, $fontSize, $columnWidth);
            $linesAr = $messageAr !== '' ? $this->wrap($messageAr, $font, $fontSize, $columnWidth) : [];
        }

        $panelTop = 50;
        $panelHeight = min($height - 100, max(130, max(count($lines), count($linesAr)) * $lineHeight + 70));
        $panel = imagecolorallocatealpha($canvas, 255, 255, 255, 22);
        imagefilledrectangle($canvas, 50, $panelTop, self::WIDTH - 50, $panelTop + $panelHeight, $panel);

        $textColor = imagecolorallocate($canvas, 31, 41, 55);
        $y = $panelTop + 58;
        foreach ($lines as $line) {
            if ($y > $panelTop + $panelHeight - 20) break;
            imagettftext($canvas, $fontSize, 0, 85, $y, $textColor, $font, $line);
            $y += $lineHeight;
        }

        if ($linesAr) {
            $divider = imagecolorallocatealpha($canvas, 31, 41, 55, 85);
            imageline($canvas, self::WIDTH / 2, $panelTop + 30, self::WIDTH / 2, $panelTop + $panelHeight - 30, $divider);
            $y = $panelTop + 58;
            foreach ($linesAr as $line) {
                if ($y > $panelTop + $panelHeight - 20) break;
                $line = $this->shapeArabicForGd($line);
                $box = imagettfbbox($fontSize, 0, $font, $line);
                $lineWidth = abs($box[2] - $box[0]);
                imagettftext($canvas, $fontSize, 0, self::WIDTH - 85 - $lineWidth, $y, $textColor, $font, $line);
                $y += $lineHeight;
            }
        }

        $directory = storage_path('app/birthday-wish-renders');
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the birthday image render directory.');
        }
        $output = $directory . DIRECTORY_SEPARATOR . uniqid('birthday-', true) . '.jpg';
        if (!imagejpeg($canvas, $output, 90)) {
            imagedestroy($canvas);
            throw new RuntimeException('Unable to render the birthday email image.');
        }
        imagedestroy($canvas);

        return $output;
    }

    private function openImage(string $path)
    {
        $mime = mime_content_type($path);
        $image = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/webp' => imagecreatefromwebp($path),
            default => false,
        };
        if (!$image) throw new RuntimeException('The birthday background image could not be opened.');
        return $image;
    }

    private function fontPath(): string
    {
        foreach ([
            'C:\\Windows\\Fonts\\arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        ] as $font) {
            if (is_file($font)) return $font;
        }
        throw new RuntimeException('No supported TrueType font was found for birthday email rendering.');
    }

    private function wrap(string $message, string $font, int $size, int $maxWidth): array
    {
        $result = [];
        foreach (preg_split('/\R/u', $message) as $paragraph) {
            if (trim($paragraph) === '') {
                $result[] = '';
                continue;
            }
            $line = '';
            foreach (preg_split('/\s+/u', trim($paragraph)) as $word) {
                $candidate = $line === '' ? $word : $line . ' ' . $word;
                $box = imagettfbbox($size, 0, $font, $candidate);
                if ($line !== '' && abs($box[2] - $box[0]) > $maxWidth) {
                    $result[] = $line;
                    $line = $word;
                } else {
                    $line = $candidate;
                }
            }
            $result[] = $line;
        }
        return $result;
    }

    /** Convert Arabic letters to presentation forms and visual RTL order for GD/FreeType. */
    private function shapeArabicForGd(string $text): string
    {
        $forms = $this->arabicForms();
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $shaped = [];

        foreach ($chars as $index => $char) {
            $code = mb_ord($char, 'UTF-8');
            if (!isset($forms[$code])) {
                $shaped[] = $char;
                continue;
            }

            [$isolated, $final, $initial, $medial] = $forms[$code];
            $previous = $index > 0 ? mb_ord($chars[$index - 1], 'UTF-8') : null;
            $next = $index + 1 < count($chars) ? mb_ord($chars[$index + 1], 'UTF-8') : null;
            $joinsPrevious = $previous !== null && isset($forms[$previous])
                && $forms[$previous][2] !== null && $final !== null;
            $joinsNext = $next !== null && isset($forms[$next])
                && $initial !== null && $forms[$next][1] !== null;

            $shaped[] = match (true) {
                $joinsPrevious && $joinsNext && $medial !== null => $medial,
                $joinsPrevious && $final !== null => $final,
                $joinsNext && $initial !== null => $initial,
                default => $isolated,
            };
        }

        $runs = preg_split(
            '/([\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]+)/u',
            implode('', $shaped),
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        );

        return implode('', array_map(function (string $run): string {
            return preg_match('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $run)
                ? implode('', array_reverse(preg_split('//u', $run, -1, PREG_SPLIT_NO_EMPTY)))
                : $run;
        }, array_reverse($runs)));
    }

    /** @return array<int, array{string, ?string, ?string, ?string}> */
    private function arabicForms(): array
    {
        $hex = fn (string $value): string => mb_chr(hexdec($value), 'UTF-8');
        $rows = [
            0x0621 => ['FE80', null, null, null], 0x0622 => ['FE81', 'FE82', null, null],
            0x0623 => ['FE83', 'FE84', null, null], 0x0624 => ['FE85', 'FE86', null, null],
            0x0625 => ['FE87', 'FE88', null, null], 0x0626 => ['FE89', 'FE8A', 'FE8B', 'FE8C'],
            0x0627 => ['FE8D', 'FE8E', null, null], 0x0628 => ['FE8F', 'FE90', 'FE91', 'FE92'],
            0x0629 => ['FE93', 'FE94', null, null], 0x062A => ['FE95', 'FE96', 'FE97', 'FE98'],
            0x062B => ['FE99', 'FE9A', 'FE9B', 'FE9C'], 0x062C => ['FE9D', 'FE9E', 'FE9F', 'FEA0'],
            0x062D => ['FEA1', 'FEA2', 'FEA3', 'FEA4'], 0x062E => ['FEA5', 'FEA6', 'FEA7', 'FEA8'],
            0x062F => ['FEA9', 'FEAA', null, null], 0x0630 => ['FEAB', 'FEAC', null, null],
            0x0631 => ['FEAD', 'FEAE', null, null], 0x0632 => ['FEAF', 'FEB0', null, null],
            0x0633 => ['FEB1', 'FEB2', 'FEB3', 'FEB4'], 0x0634 => ['FEB5', 'FEB6', 'FEB7', 'FEB8'],
            0x0635 => ['FEB9', 'FEBA', 'FEBB', 'FEBC'], 0x0636 => ['FEBD', 'FEBE', 'FEBF', 'FEC0'],
            0x0637 => ['FEC1', 'FEC2', 'FEC3', 'FEC4'], 0x0638 => ['FEC5', 'FEC6', 'FEC7', 'FEC8'],
            0x0639 => ['FEC9', 'FECA', 'FECB', 'FECC'], 0x063A => ['FECD', 'FECE', 'FECF', 'FED0'],
            0x0641 => ['FED1', 'FED2', 'FED3', 'FED4'], 0x0642 => ['FED5', 'FED6', 'FED7', 'FED8'],
            0x0643 => ['FED9', 'FEDA', 'FEDB', 'FEDC'], 0x0644 => ['FEDD', 'FEDE', 'FEDF', 'FEE0'],
            0x0645 => ['FEE1', 'FEE2', 'FEE3', 'FEE4'], 0x0646 => ['FEE5', 'FEE6', 'FEE7', 'FEE8'],
            0x0647 => ['FEE9', 'FEEA', 'FEEB', 'FEEC'], 0x0648 => ['FEED', 'FEEE', null, null],
            0x0649 => ['FEEF', 'FEF0', null, null], 0x064A => ['FEF1', 'FEF2', 'FEF3', 'FEF4'],
            0x0671 => ['FB50', 'FB51', null, null], 0x067E => ['FB56', 'FB57', 'FB58', 'FB59'],
            0x0686 => ['FB7A', 'FB7B', 'FB7C', 'FB7D'], 0x0698 => ['FB8A', 'FB8B', null, null],
            0x06A4 => ['FB6A', 'FB6B', 'FB6C', 'FB6D'], 0x06A9 => ['FB8E', 'FB8F', 'FB90', 'FB91'],
            0x06AF => ['FB92', 'FB93', 'FB94', 'FB95'], 0x06CC => ['FBFC', 'FBFD', 'FBFE', 'FBFF'],
        ];

        return array_map(fn (array $row): array => array_map(
            fn ($form) => $form === null ? null : $hex($form),
            $row,
        ), $rows);
    }
}
