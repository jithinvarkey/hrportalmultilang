<?php

namespace App\Services;

use RuntimeException;

/**
 * Writes the birthday person's name onto HR's artwork, inside a text area chosen on the Birthday wishes screen
 * (for example the blank frame in the middle of the picture). The message itself is sent as normal email text.
 */
class BirthdayWishImageComposer
{
    private const WIDTH = 1200;
    public const DEFAULT_AREA = ['x' => 25, 'y' => 35, 'width' => 50, 'height' => 30];
    public const DEFAULT_COLOR = '#1e3a5f';

    /**
     * @param array{x: float, y: float, width: float, height: float} $area text area as percentages of the image
     */
    public function compose(string $sourcePath, string $name, string $nameAr = '', array $area = self::DEFAULT_AREA, string $color = self::DEFAULT_COLOR): string
    {
        $source = $this->openImage($sourcePath);
        $height = max(1, (int) round(self::WIDTH * imagesy($source) / imagesx($source)));
        $canvas = imagecreatetruecolor(self::WIDTH, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, self::WIDTH, $height, imagesx($source), imagesy($source));

        $boxLeft = (int) round(self::WIDTH * $area['x'] / 100);
        $boxTop = (int) round($height * $area['y'] / 100);
        $boxWidth = max(40, (int) round(self::WIDTH * $area['width'] / 100));
        $boxHeight = max(40, (int) round($height * $area['height'] / 100));

        $font = $this->fontPath();
        $nameAr = trim($nameAr);
        // Largest size at which the name and the Arabic name fit the area. The first pass keeps the layout tidy
        // (English on at most two lines, Arabic on one); only if nothing fits that way may they wrap further.
        foreach ([[2, 1], [3, 3]] as [$maxLines, $maxLinesAr]) {
            for ($size = 72; $size >= 16; $size -= 2) {
                $lines = $this->wrap(trim($name), $font, $size, $boxWidth);
                $arSize = (int) round($size * 0.8);
                $linesAr = $nameAr !== '' ? $this->wrap($nameAr, $font, $arSize, $boxWidth) : [];
                $lineHeight = (int) round($size * 1.3);
                $arLineHeight = (int) round($arSize * 1.4);
                $total = count($lines) * $lineHeight + ($linesAr ? (int) round($size * 0.3) + count($linesAr) * $arLineHeight : 0);
                if (count($lines) <= $maxLines && count($linesAr) <= $maxLinesAr && $total <= $boxHeight
                    && $this->widest($lines, $font, $size) <= $boxWidth
                    && $this->widest(array_map(fn ($l) => $this->shapeArabicForGd($l), $linesAr), $font, $arSize) <= $boxWidth) {
                    break 2;
                }
            }
        }

        $textColor = $this->allocateHex($canvas, $color);
        $y = $boxTop + (int) round(($boxHeight - $total) / 2);
        foreach ($lines as $line) {
            $this->centered($canvas, $font, $size, $boxLeft, $boxWidth, $y + (int) round($size * 1.05), $textColor, $line);
            $y += $lineHeight;
        }
        if ($linesAr) {
            $y += (int) round($size * 0.3);
            foreach ($linesAr as $line) {
                $this->centered($canvas, $font, $arSize, $boxLeft, $boxWidth, $y + (int) round($arSize * 1.1), $textColor, $this->shapeArabicForGd($line));
                $y += $arLineHeight;
            }
        }

        $directory = storage_path('app/birthday-wish-renders');
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the birthday image render directory.');
        }
        $output = $directory . DIRECTORY_SEPARATOR . uniqid('birthday-', true) . '.jpg';
        if (!imagejpeg($canvas, $output, 90)) {
            throw new RuntimeException('Unable to render the birthday email image.');
        }

        return $output;
    }

    /** Draw one line centred horizontally in the box, with a faint white halo so it stays legible on busy artwork. */
    private function centered($canvas, string $font, int $size, int $left, int $width, int $baseline, int $color, string $text): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        $x = $left + (int) round(($width - abs($box[2] - $box[0])) / 2) - $box[0];
        $halo = imagecolorallocatealpha($canvas, 255, 255, 255, 70);
        foreach ([[-2, 0], [2, 0], [0, -2], [0, 2]] as [$dx, $dy]) {
            imagettftext($canvas, $size, 0, $x + $dx, $baseline + $dy, $halo, $font, $text);
        }
        imagettftext($canvas, $size, 0, $x, $baseline, $color, $font, $text);
    }

    private function widest(array $lines, string $font, int $size): int
    {
        $widest = 0;
        foreach ($lines as $line) {
            $box = imagettfbbox($size, 0, $font, $line);
            $widest = max($widest, abs($box[2] - $box[0]));
        }
        return $widest;
    }

    private function allocateHex($canvas, string $hex): int
    {
        $hex = preg_match('/^#?([0-9a-f]{6})$/i', $hex, $m) ? $m[1] : ltrim(self::DEFAULT_COLOR, '#');
        return imagecolorallocate($canvas, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
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
