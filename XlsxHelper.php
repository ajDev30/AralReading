<?php
/**
 * XlsxHelper - Pure PHP ZIP/XLSX Reader & Writer (Zero external extensions required, works with or without ZipArchive)
 * Preserves original styles, themes, column widths, and formulas of .xlsx templates.
 */
class XlsxHelper
{
    /**
     * Read all files from a ZIP/.xlsx archive into an associative array [entryName => rawContent]
     */
    public static function readZipEntries(string $zipPath): array
    {
        if (!file_exists($zipPath)) {
            throw new Exception("Template file not found: " . $zipPath);
        }

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) === true) {
                $entries = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    $entries[$name] = $zip->getFromIndex($i);
                }
                $zip->close();
                return $entries;
            }
        }

        // Pure PHP fallback using Central Directory parsing + gzinflate
        $data = file_get_contents($zipPath);
        $len = strlen($data);
        $entries = [];

        // Locate End of Central Directory (EOCD) signature 0x06054b50
        $eocdPos = strrpos($data, "\x50\x4b\x05\x06");
        if ($eocdPos === false) {
            throw new Exception("Invalid XLSX/ZIP archive: EOCD not found.");
        }

        $eocd = unpack('vdisk/vcdDisk/viskEntries/vtotalEntries/VcdSize/VcdOffset', substr($data, $eocdPos + 4, 16));
        $cdPos = $eocd['cdOffset'];
        $total = $eocd['totalEntries'];

        for ($i = 0; $i < $total; $i++) {
            if (substr($data, $cdPos, 4) !== "\x50\x4b\x01\x02") {
                break;
            }
            $hdr = unpack(
                'vverMade/vverNeed/vflag/vmethod/vmtime/vmdate/Vcrc/VcompSize/VuncompSize/vnameLen/vextraLen/vcommentLen/vdiskStart/vintAttr/VextAttr/VlocalOffset',
                substr($data, $cdPos + 4, 42)
            );
            $name = substr($data, $cdPos + 46, $hdr['nameLen']);
            $cdPos += 46 + $hdr['nameLen'] + $hdr['extraLen'] + $hdr['commentLen'];

            // Read local file header at localOffset
            $locPos = $hdr['localOffset'];
            if (substr($data, $locPos, 4) !== "\x50\x4b\x03\x04") {
                continue;
            }
            $locHdr = unpack('vverNeed/vflag/vmethod/vmtime/vmdate/Vcrc/VcompSize/VuncompSize/vnameLen/vextraLen', substr($data, $locPos + 4, 26));
            $dataStart = $locPos + 30 + $locHdr['nameLen'] + $locHdr['extraLen'];
            $compData = substr($data, $dataStart, $hdr['compSize']);

            if ($hdr['method'] === 0) {
                $entries[$name] = $compData;
            } elseif ($hdr['method'] === 8) {
                $inflated = @gzinflate($compData);
                $entries[$name] = ($inflated !== false) ? $inflated : '';
            }
        }

        return $entries;
    }

    /**
     * Build a valid ZIP/.xlsx binary string from an associative array [entryName => rawContent]
     */
    public static function buildZipBinary(array $entries): string
    {
        $localParts = [];
        $centralParts = [];
        $offset = 0;
        $count = 0;

        // DOS timestamp for current time
        $now = getdate();
        $dosTime = (($now['hours'] << 11) | ($now['minutes'] << 5) | ($now['seconds'] >> 1)) & 0xFFFF;
        $dosDate = (((max(1980, $now['year']) - 1980) << 9) | ($now['mon'] << 5) | $now['mday']) & 0xFFFF;

        foreach ($entries as $name => $content) {
            $name = str_replace('\\', '/', $name);
            $uncompSize = strlen($content);
            $crc = crc32($content);
            // Convert signed crc32 on 32-bit systems to unsigned
            if ($crc < 0) {
                $crc += 4294967296;
            }
            $compData = gzdeflate($content, 6);
            $compSize = strlen($compData);
            $nameLen = strlen($name);

            // Local file header (0x04034b50)
            $localHeader = "\x50\x4b\x03\x04"
                . pack('vvvvvVVVvv', 20, 0, 8, $dosTime, $dosDate, $crc, $compSize, $uncompSize, $nameLen, 0)
                . $name;

            $localChunk = $localHeader . $compData;
            $localParts[] = $localChunk;

            // Central directory file header (0x02014b50)
            $centralHeader = "\x50\x4b\x01\x02"
                . pack('vvvvvvVVVvvvvvVV', 20, 20, 0, 8, $dosTime, $dosDate, $crc, $compSize, $uncompSize, $nameLen, 0, 0, 0, 0, 32, $offset)
                . $name;
            $centralParts[] = $centralHeader;

            $offset += strlen($localChunk);
            $count++;
        }

        $centralDir = implode('', $centralParts);
        $centralSize = strlen($centralDir);

        // End of central directory record (0x06054b50)
        $eocd = "\x50\x4b\x05\x06"
            . pack('vvvvVVv', 0, 0, $count, $count, $centralSize, $offset, 0);

        return implode('', $localParts) . $centralDir . $eocd;
    }

    /**
     * Escape text safely for XML inlineStr or formulas
     */
    public static function xmlEscape(string $str): string
    {
        return htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Build an inline string cell `<c r="B8" s="7" t="inlineStr"><is><t>...</t></is></c>`
     */
    public static function inlineStrCell(string $ref, int $style, string $text): string
    {
        return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t>' . self::xmlEscape($text) . '</t></is></c>';
    }

    /**
     * Build a numeric cell `<c r="F8" s="8" t="n"><v>12</v></c>` (or empty styled cell if null/empty)
     */
    public static function numCell(string $ref, int $style, $val): string
    {
        if ($val === null || $val === '') {
            return '<c r="' . $ref . '" s="' . $style . '"/>';
        }
        return '<c r="' . $ref . '" s="' . $style . '" t="n"><v>' . (is_numeric($val) ? $val : 0) . '</v></c>';
    }

    /**
     * Build a formula cell `<c r="G8" s="9"><f>...</f><v>...</v></c>`
     */
    public static function formulaCell(string $ref, int $style, string $formula, $cachedVal = '', string $typeAttr = ''): string
    {
        $tAttr = $typeAttr !== '' ? ' t="' . $typeAttr . '"' : '';
        $vTag = ($cachedVal !== '' && $cachedVal !== null) ? '<v>' . self::xmlEscape((string)$cachedVal) . '</v>' : '<v/>';
        return '<c r="' . $ref . '" s="' . $style . '"' . $tAttr . '><f>' . self::xmlEscape($formula) . '</f>' . $vTag . '</c>';
    }
}
