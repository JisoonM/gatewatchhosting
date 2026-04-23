<?php
if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit("Forbidden: this script can only be run from CLI.\n");
}

// Generate a default profile picture
$width = 400;
$height = 400;
$image = imagecreatetruecolor($width, $height);

// Set background color (light gray)
$bgColor = imagecolorallocate($image, 226, 232, 240);
imagefill($image, 0, 0, $bgColor);

// Set icon color (slate)
$iconColor = imagecolorallocate($image, 100, 116, 139);

// Draw a simple avatar shape
$centerX = $width / 2;
$centerY = $height / 2;

// Head circle
$headRadius = $width * 0.2;
imagefilledellipse($image, $centerX, $centerY - ($height * 0.1), $headRadius * 2, $headRadius * 2, $iconColor);

// Body
$bodyWidth = $headRadius * 2.2;
$bodyHeight = $height * 0.3;
$bodyX = $centerX - ($bodyWidth / 2);
$bodyY = $centerY + ($height * 0.05);
imagefilledarc($image, $centerX, $bodyY + ($bodyHeight * 0.8), $bodyWidth, $bodyHeight * 2, 0, 180, $iconColor, IMG_ARC_PIE);

// Save generated image file
imagepng($image, __DIR__ . '/assets/images/default-profile.png');
imagedestroy($image);

echo "Default profile picture created successfully!";
?>