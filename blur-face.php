<?php

class FaceBlurAPI {
    
    private $uploadDir = 'faces';
    private $image;
    private $imagePath;
    private $outputPath;
    private $faces = [];
    private $requestMethod;
    
    public function __construct() {
        if (!file_exists($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
        
        @ini_set('memory_limit', '256M');
        @set_time_limit(30);
        
        $this->requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }
    
    public function processRequest() {
        try {
            if ($this->requestMethod === 'GET') {
                return $this->processGetRequest();
            } elseif ($this->requestMethod === 'POST') {
                return $this->processPostRequest();
            } else {
                return "خطا: متد درخواست پشتیبانی نمی‌شود";
            }
        } catch (Exception $e) {
            error_log("FaceBlur Error: " . $e->getMessage());
            return "خطا: " . $e->getMessage();
        }
    }
    
    private function processGetRequest() {
        if (!isset($_GET['url']) || empty($_GET['url'])) {
            return "خطا: لطفاً لینک عکس را با پارامتر url ارسال کنید";
        }
        
        $imageUrl = $_GET['url'];
        
        if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            return "خطا: لینک وارد شده معتبر نیست";
        }
        
        $imageContent = @file_get_contents($imageUrl);
        if (!$imageContent) {
            return "خطا: امکان دریافت عکس از لینک وجود ندارد";
        }
        
        $extension = $this->getExtensionFromUrl($imageUrl);
        $fileName = time() . '_' . uniqid() . '.' . $extension;
        $this->imagePath = $this->uploadDir . '/' . $fileName;
        
        if (!file_put_contents($this->imagePath, $imageContent)) {
            return "خطا: خطا در ذخیره عکس روی سرور";
        }
        
        return $this->detectAndBlurFaces();
    }
    
    private function processPostRequest() {
        if (!isset($_FILES['image'])) {
            return "خطا: هیچ فایلی ارسال نشده است";
        }
        
        $file = $_FILES['image'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE => "حجم فایل بیشتر از حد مجاز سرور است",
                UPLOAD_ERR_FORM_SIZE => "حجم فایل بیشتر از حد مجاز فرم است",
                UPLOAD_ERR_PARTIAL => "فایل ناقص آپلود شد",
                UPLOAD_ERR_NO_FILE => "فایلی انتخاب نشده است",
            ];
            return "خطا: " . ($errors[$file['error']] ?? "خطای ناشناخته");
        }
        
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($fileInfo, $file['tmp_name']);
        finfo_close($fileInfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            return "خطا: فرمت فایل مجاز نیست";
        }
        
        if ($file['size'] > 10 * 1024 * 1024) {
            return "خطا: حجم فایل نباید بیشتر از ۱۰ مگابایت باشد";
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = time() . '_' . uniqid() . '.' . $extension;
        $this->imagePath = $this->uploadDir . '/' . $fileName;
        
        if (!move_uploaded_file($file['tmp_name'], $this->imagePath)) {
            return "خطا: خطا در ذخیره فایل روی سرور";
        }
        
        return $this->detectAndBlurFaces();
    }
    
    private function getExtensionFromUrl($url) {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        return in_array($ext, $allowed) ? $ext : 'jpg';
    }
    
    private function detectAndBlurFaces() {
        try {
            $this->image = $this->loadImage($this->imagePath);
            if (!$this->image) {
                return "خطا: تصویر قابل بارگذاری نیست";
            }
            
            $width = imagesx($this->image);
            $height = imagesy($this->image);
            
            $this->faces = $this->detectFaces($this->image, $width, $height);
            
            if (count($this->faces) > 0) {
                $blurred = $this->blurFaces($this->image, $this->faces);
                $this->outputPath = $this->uploadDir . '/blurred_' . time() . '_' . uniqid() . '.jpg';
                $this->saveImage($blurred, $this->outputPath);
                @imagedestroy($blurred);
                $this->outputImage($this->outputPath);
            } else {
                $this->outputImage($this->imagePath);
            }
            
            $this->cleanupFiles();
            exit;
            
        } catch (Exception $e) {
            $this->cleanupFiles();
            return "خطا در پردازش تصویر: " . $e->getMessage();
        }
    }
    
    private function detectFaces($image, $width, $height) {
        $faces = [];
        
        $maxDim = max($width, $height);
        $scale = min(1, 400 / $maxDim);
        $smallW = (int)($width * $scale);
        $smallH = (int)($height * $scale);
        
        if ($smallW < 40 || $smallH < 40) {
            $smallW = $width;
            $smallH = $height;
            $scale = 1;
        }
        
        $smallImage = $this->resizeImage($image, $smallW, $smallH);
        if (!$smallImage) {
            return $faces;
        }
        
        $sizes = $this->getFaceSizes($smallW, $smallH);
        
        foreach ($sizes as $size) {
            if ($size > min($smallW, $smallH)) continue;
            
            $step = max(4, (int)($size * 0.08));
            
            for ($x = 0; $x <= $smallW - $size; $x += $step) {
                for ($y = 0; $y <= $smallH - $size; $y += $step) {
                    $score = $this->checkFace($smallImage, $x, $y, $size);
                    
                    if ($score > 0.6) {
                        $faces[] = [
                            'x' => (int)($x / $scale),
                            'y' => (int)($y / $scale),
                            'width' => (int)($size / $scale),
                            'height' => (int)($size / $scale),
                            'confidence' => min(95, (int)($score * 100))
                        ];
                    }
                }
            }
        }
        
        @imagedestroy($smallImage);
        
        return $this->removeDuplicateFaces($faces);
    }
    
    private function getFaceSizes($w, $h) {
        $minDim = min($w, $h);
        $sizes = [];
        
        if ($minDim >= 500) {
            $sizes = [50, 70, 100, 140, 190];
        } elseif ($minDim >= 300) {
            $sizes = [40, 55, 75, 110, 150];
        } elseif ($minDim >= 150) {
            $sizes = [30, 45, 65, 90];
        } else {
            $sizes = [25, 35, 50];
        }
        
        return $sizes;
    }
    
    private function checkFace($image, $x, $y, $size) {
        $score = 0;
        $total = 0;
        
        $skinScore = $this->checkSkin($image, $x, $y, $size);
        if ($skinScore < 0.15) {
            return 0;
        }
        $score += $skinScore * 0.35;
        $total += 0.35;
        
        $eyeScore = $this->checkEyes($image, $x, $y, $size);
        $score += $eyeScore * 0.30;
        $total += 0.30;
        
        $mouthScore = $this->checkMouth($image, $x, $y, $size);
        $score += $mouthScore * 0.20;
        $total += 0.20;
        
        $symScore = $this->checkSymmetry($image, $x, $y, $size);
        $score += $symScore * 0.15;
        $total += 0.15;
        
        return $total > 0 ? $score / $total : 0;
    }
    
    private function checkSkin($image, $x, $y, $size) {
        $skinCount = 0;
        $total = 0;
        $step = max(2, (int)($size / 8));
        
        for ($dy = 0; $dy < $size; $dy += $step) {
            for ($dx = 0; $dx < $size; $dx += $step) {
                $px = $x + $dx;
                $py = $y + $dy;
                if ($px >= imagesx($image) || $py >= imagesy($image)) continue;
                
                $rgb = @imagecolorat($image, $px, $py);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                
                if ($this->isSkin($r, $g, $b)) {
                    $skinCount++;
                }
                $total++;
            }
        }
        
        return $total > 0 ? $skinCount / $total : 0;
    }
    
    private function isSkin($r, $g, $b) {
        if ($r < 30 || $g < 20 || $b < 15) return false;
        if ($r < $g || $r < $b) return false;
        if (max($r, $g, $b) - min($r, $g, $b) < 8) return false;
        
        $cb = 128 - 0.168736 * $r - 0.331264 * $g + 0.5 * $b;
        $cr = 128 + 0.5 * $r - 0.418688 * $g - 0.081312 * $b;
        
        $profile1 = ($cb >= 95 && $cb <= 120 && $cr >= 140 && $cr <= 165);
        $profile2 = ($cb >= 100 && $cb <= 125 && $cr >= 145 && $cr <= 170);
        
        return $profile1 || $profile2;
    }
    
    private function checkEyes($image, $x, $y, $size) {
        $score = 0;
        $checks = 0;
        
        $eyeY = (int)($y + $size * 0.32);
        $eyeW = (int)($size * 0.15);
        $eyeH = (int)($size * 0.08);
        
        $positions = [
            ['x' => (int)($x + $size * 0.25), 'y' => $eyeY],
            ['x' => (int)($x + $size * 0.75), 'y' => $eyeY]
        ];
        
        foreach ($positions as $pos) {
            $ex = $pos['x'];
            $ey = $pos['y'];
            if ($ex + $eyeW >= imagesx($image) || $ey + $eyeH >= imagesy($image)) continue;
            
            $dark = 0;
            $total = 0;
            
            for ($dy = 0; $dy < $eyeH; $dy += 2) {
                for ($dx = 0; $dx < $eyeW; $dx += 2) {
                    $rgb = @imagecolorat($image, $ex + $dx, $ey + $dy);
                    $brightness = (($rgb >> 16) & 0xFF) * 0.299 + 
                                 (($rgb >> 8) & 0xFF) * 0.587 + 
                                 ($rgb & 0xFF) * 0.114;
                    if ($brightness < 80) $dark++;
                    $total++;
                }
            }
            
            if ($total > 0 && ($dark / $total) > 0.15) {
                $score++;
            }
            $checks++;
        }
        
        return $checks > 0 ? $score / $checks : 0;
    }
    
    private function checkMouth($image, $x, $y, $size) {
        $mx = (int)($x + $size * 0.32);
        $my = (int)($y + $size * 0.72);
        $mw = (int)($size * 0.36);
        $mh = (int)($size * 0.08);
        
        if ($mx + $mw >= imagesx($image) || $my + $mh >= imagesy($image)) {
            return 0;
        }
        
        $dark = 0;
        $total = 0;
        
        for ($dy = 0; $dy < $mh; $dy += 2) {
            for ($dx = 0; $dx < $mw; $dx += 2) {
                $rgb = @imagecolorat($image, $mx + $dx, $my + $dy);
                $brightness = (($rgb >> 16) & 0xFF) * 0.299 + 
                             (($rgb >> 8) & 0xFF) * 0.587 + 
                             ($rgb & 0xFF) * 0.114;
                if ($brightness < 90) $dark++;
                $total++;
            }
        }
        
        return $total > 0 ? min(1, ($dark / $total) * 2) : 0;
    }
    
    private function checkSymmetry($image, $x, $y, $size) {
        $cx = $x + $size / 2;
        $score = 0;
        $checks = 0;
        $step = max(2, (int)($size / 10));
        
        for ($dy = 0; $dy < $size * 0.4; $dy += $step) {
            $py = $y + $size * 0.25 + $dy;
            if ($py >= imagesy($image)) break;
            
            for ($dx = 0; $dx < $size * 0.2; $dx += $step) {
                $lx = (int)($cx - $dx);
                $rx = (int)($cx + $dx);
                if ($lx < 0 || $rx >= imagesx($image)) continue;
                
                $rgbL = @imagecolorat($image, $lx, (int)$py);
                $rgbR = @imagecolorat($image, $rx, (int)$py);
                
                $rL = ($rgbL >> 16) & 0xFF; $gL = ($rgbL >> 8) & 0xFF; $bL = $rgbL & 0xFF;
                $rR = ($rgbR >> 16) & 0xFF; $gR = ($rgbR >> 8) & 0xFF; $bR = $rgbR & 0xFF;
                
                $diff = abs($rL - $rR) + abs($gL - $gR) + abs($bL - $bR);
                if ($diff < 80) $score++;
                $checks++;
            }
        }
        
        return $checks > 0 ? $score / $checks : 0;
    }
    
    private function removeDuplicateFaces($faces) {
        if (empty($faces)) return $faces;
        
        usort($faces, function($a, $b) {
            return ($b['confidence'] ?? 0) - ($a['confidence'] ?? 0);
        });
        
        $selected = [];
        $used = [];
        
        foreach ($faces as $i => $face1) {
            if (isset($used[$i])) continue;
            
            if ($face1['width'] < 25 || $face1['height'] < 25) continue;
            
            $selected[] = $face1;
            
            foreach ($faces as $j => $face2) {
                if ($i == $j || isset($used[$j])) continue;
                
                $iou = $this->calculateIoU($face1, $face2);
                if ($iou > 0.35) {
                    $used[$j] = true;
                }
            }
        }
        
        return $selected;
    }
    
    private function calculateIoU($b1, $b2) {
        $x1 = max($b1['x'], $b2['x']);
        $y1 = max($b1['y'], $b2['y']);
        $x2 = min($b1['x'] + $b1['width'], $b2['x'] + $b2['width']);
        $y2 = min($b1['y'] + $b1['height'], $b2['y'] + $b2['height']);
        
        if ($x2 <= $x1 || $y2 <= $y1) return 0;
        
        $inter = ($x2 - $x1) * ($y2 - $y1);
        $area1 = $b1['width'] * $b1['height'];
        $area2 = $b2['width'] * $b2['height'];
        $union = $area1 + $area2 - $inter;
        
        return $union > 0 ? $inter / $union : 0;
    }
    
    private function resizeImage($image, $newW, $newH) {
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }
        
        $resized = @imagecreatetruecolor($newW, $newH);
        if (!$resized) return false;
        
        @imagecopyresampled($resized, $image, 0, 0, 0, 0, $newW, $newH, imagesx($image), imagesy($image));
        return $resized;
    }
    
    private function blurFaces($image, $faces) {
        $blurred = $image;
        
        foreach ($faces as $face) {
            $blurred = $this->blurFace($blurred, $face);
        }
        
        return $blurred;
    }
    
    private function blurFace($image, $face) {
        $x = max(0, $face['x'] - 5);
        $y = max(0, $face['y'] - 5);
        $w = min(imagesx($image) - $x, $face['width'] + 10);
        $h = min(imagesy($image) - $y, $face['height'] + 10);
        
        if ($w < 20 || $h < 20) return $image;
        
        $faceImg = @imagecrop($image, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
        if (!$faceImg) return $image;
        
        $pixelSize = max(5, (int)round($w / 8));
        @imagefilter($faceImg, IMG_FILTER_PIXELATE, $pixelSize, true);
        
        for ($i = 0; $i < 4; $i++) {
            @imagefilter($faceImg, IMG_FILTER_GAUSSIAN_BLUR);
        }
        
        @imagecopy($image, $faceImg, $x, $y, 0, 0, $w, $h);
        @imagedestroy($faceImg);
        
        return $image;
    }
    
    private function loadImage($path) {
        $imageInfo = @getimagesize($path);
        if (!$imageInfo) {
            return false;
        }
        
        $mime = $imageInfo['mime'];
        $image = null;
        
        switch ($mime) {
            case 'image/jpeg':
            case 'image/jpg':
                $image = @imagecreatefromjpeg($path);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($path);
                break;
            case 'image/gif':
                $image = @imagecreatefromgif($path);
                break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $image = @imagecreatefromwebp($path);
                }
                break;
        }
        
        return $image;
    }
    
    private function saveImage($image, $path) {
        @imagejpeg($image, $path, 92);
    }
    
    private function outputImage($path) {
        if (!file_exists($path)) {
            echo "خطا: تصویر وجود ندارد";
            return;
        }
        
        $imageInfo = @getimagesize($path);
        if (!$imageInfo) {
            echo "خطا: تصویر نامعتبر";
            return;
        }
        
        $mime = $imageInfo['mime'];
        
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        @readfile($path);
    }
    
    private function cleanupFiles() {
        if (file_exists($this->imagePath)) {
            @unlink($this->imagePath);
        }
        if (file_exists($this->outputPath)) {
            @unlink($this->outputPath);
        }
    }
}

try {
    $analyzer = new FaceBlurAPI();
    echo $analyzer->processRequest();
} catch (Exception $e) {
    error_log("FaceBlur Fatal: " . $e->getMessage());
    echo "خطای داخلی سرور";
}
?>
