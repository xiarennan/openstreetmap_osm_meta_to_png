<?php
/**
 * mod_tile 元数据读取，renderd 版本为3.1。
 */

class ModTileMetaReader {
    private $tileDir = '/var/cache/renderd/tiles'; // tile_dir 目录，具体位置请查看 /etc/renderd.conf。
    private $metaTileSize = 8;
    
    public function getTile($z, $x, $y, $xmlconfig = 'default') {
        if (!$this->isValidTile($z, $x, $y)) {
            $this->errorResponse('Invalid tile coordinates');
            return false;
        }
        
        $metaPath = $this->xyzToMetaPath($xmlconfig, $x, $y, $z);
        
        if (!file_exists($metaPath)) {
            $this->errorResponse('Meta tile not found');
            return false;
        }
        
        $metaContent = file_get_contents($metaPath);
        if ($metaContent === false) {
            $this->errorResponse('Cannot read meta file');
            return false;
        }
        
        // 计算瓦片索引（列优先：x * size + y）
        $mask = $this->metaTileSize - 1;
        $tileIndex = ($x & $mask) * $this->metaTileSize + ($y & $mask);
        
        $tileData = $this->extractPngByHeader($metaContent, $tileIndex);
        
        if ($tileData === false || !$this->isValidPng($tileData)) {
            $this->errorResponse('Cannot extract tile');
            return false;
        }
        
        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($tileData));
        header('Cache-Control: public, max-age=3600');
        echo $tileData;
        return true;
    }
    
    private function xyzToMetaPath($xmlconfig, $x, $y, $z) {
        $hash = $this->calculateHash($x, $y);
        return sprintf("%s/%s/%d/%d/%d/%d/%d/%d.meta",
            $this->tileDir, $xmlconfig, $z,
            $hash[4], $hash[3], $hash[2], $hash[1], $hash[0]
        );
    }
    
    private function calculateHash($x, $y) {
        $hash = array_fill(0, 5, 0);
        $mask = $this->metaTileSize - 1;
        $meta_x = $x & ~$mask;
        $meta_y = $y & ~$mask;
        
        for ($i = 0; $i < 5; $i++) {
            $hash[$i] = (($meta_x & 0x0F) << 4) | ($meta_y & 0x0F);
            $meta_x >>= 4;
            $meta_y >>= 4;
        }
        
        return $hash;
    }
    
    private function extractPngByHeader($metaContent, $tileIndex) {
        $pngHeader = "\x89PNG\r\n\x1a\n";
        $tiles = [];
        $pos = 0;
        $count = 0;
        
        while (($pos = strpos($metaContent, $pngHeader, $pos)) !== false) {
            $iendPos = strpos($metaContent, 'IEND', $pos);
            if ($iendPos === false) break;
            
            $endPos = $iendPos + 8;
            $tiles[$count] = substr($metaContent, $pos, $endPos - $pos);
            
            if ($count >= $tileIndex) break;
            
            $count++;
            $pos = $endPos;
        }
        
        return ($tileIndex >= 0 && $tileIndex < count($tiles)) ? $tiles[$tileIndex] : false;
    }
    
    private function isValidPng($data) {
        return strlen($data) >= 8 && substr($data, 0, 8) === "\x89PNG\x0d\x0a\x1a\x0a";
    }
    
    private function isValidTile($z, $x, $y) {
        if (!is_numeric($z) || !is_numeric($x) || !is_numeric($y)) return false;
        
        $z = intval($z); $x = intval($x); $y = intval($y);
        if ($z < 0 || $z > 20) return false;
        
        $maxTile = (1 << $z) - 1;
        return !($x < 0 || $x > $maxTile || $y < 0 || $y > $maxTile);
    }
    
    private function errorResponse($message) {
        header('HTTP/1.1 404 Not Found');
        echo "Error: " . $message;
    }
}

// 主程序
$reader = new ModTileMetaReader();

$z = isset($_GET['z']) ? intval($_GET['z']) : null;
$x = isset($_GET['x']) ? intval($_GET['x']) : null;
$y = isset($_GET['y']) ? intval($_GET['y']) : null;
$xmlconfig = isset($_GET['xmlconfig']) ? $_GET['xmlconfig'] : 'default'; // tile_dir 中的目录名，取决于安装时在 /etc/renderd.conf 添加的 mod_tile 段名，一般默认为 default。

if ($z !== null && $x !== null && $y !== null) {
    $reader->getTile($z, $x, $y, $xmlconfig);
} else {
    header('HTTP/1.1 400 Bad Request');
    echo "Usage: ?z=ZOOM&x=X&y=Y";
}
?>
