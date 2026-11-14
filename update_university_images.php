<?php
require_once "db_connect.php";

// Update existing universities with images
$university_images = [
    'Chulalongkorn University' => 'https://images.unsplash.com/photo-1562774053-701939374585?w=800&h=600&fit=crop',
    'Thammasat University' => 'https://images.unsplash.com/photo-1541339907198-e08756dedf3f?w=800&h=600&fit=crop',
    'Mahidol University' => 'https://images.unsplash.com/photo-1523050854058-8df90110c9f1?w=800&h=600&fit=crop',
    'Kasetsart University' => 'https://images.unsplash.com/photo-1564981797816-1043664bf78d?w=800&h=600&fit=crop',
    'King Mongkut\'s University of Technology Thonburi' => 'https://images.unsplash.com/photo-1581093458791-9f3c3900df4b?w=800&h=600&fit=crop',
    'Bangkok University' => 'https://images.unsplash.com/photo-1568605117036-5fe5e7bab0b7?w=800&h=600&fit=crop',
    'Assumption University' => 'https://images.unsplash.com/photo-1571260899304-425eee4c7efc?w=800&h=600&fit=crop',
    'Chiang Mai University' => 'https://images.unsplash.com/photo-1498243691581-b145c3f54a5a?w=800&h=600&fit=crop',
    'Prince of Songkla University' => 'https://images.unsplash.com/photo-1503676260728-1c00da094a0b?w=800&h=600&fit=crop',
    'Asian Institute of Technology' => 'https://images.unsplash.com/photo-1559136555-9303baea8ebd?w=800&h=600&fit=crop',
    'National University of Singapore' => 'https://images.unsplash.com/photo-1523580494863-6f3031224c94?w=800&h=600&fit=crop',
    'Nanyang Technological University' => 'https://images.unsplash.com/photo-1552664730-d307ca884978?w=800&h=600&fit=crop',
    'Singapore Management University' => 'https://images.unsplash.com/photo-1544531586-fde5298cdd40?w=800&h=600&fit=crop',
    'Singapore University of Technology and Design' => 'https://images.unsplash.com/photo-1519389950473-47ba0277781c?w=800&h=600&fit=crop',
    'Singapore Institute of Technology' => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?w=800&h=600&fit=crop',
    'INSEAD Asia Campus' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=800&h=600&fit=crop',
    'Singapore University of Social Sciences' => 'https://images.unsplash.com/photo-1524178232363-1fb2b075b955?w=800&h=600&fit=crop',
    'LASALLE College of the Arts' => 'https://images.unsplash.com/photo-1513364776144-60967b0f800f?w=800&h=600&fit=crop',
    'Nanyang Academy of Fine Arts' => 'https://images.unsplash.com/photo-1460661419201-fd4cecdf8a8b?w=800&h=600&fit=crop',
    'James Cook University Singapore' => 'https://images.unsplash.com/photo-1541339907198-e08756dedf3f?w=800&h=600&fit=crop',
    'University of Malaya' => 'https://images.unsplash.com/photo-1564981797816-1043664bf78d?w=800&h=600&fit=crop',
    'Universiti Teknologi Malaysia' => 'https://images.unsplash.com/photo-1581093458791-9f3c3900df4b?w=800&h=600&fit=crop',
    'Universiti Sains Malaysia' => 'https://images.unsplash.com/photo-1523050854058-8df90110c9f1?w=800&h=600&fit=crop',
    'Universiti Kebangsaan Malaysia' => 'https://images.unsplash.com/photo-1562774053-701939374585?w=800&h=600&fit=crop',
    'Universiti Putra Malaysia' => 'https://images.unsplash.com/photo-1559136555-9303baea8ebd?w=800&h=600&fit=crop',
    'Universiti Teknologi MARA' => 'https://images.unsplash.com/photo-1568605117036-5fe5e7bab0b7?w=800&h=600&fit=crop',
    'International Islamic University Malaysia' => 'https://images.unsplash.com/photo-1571260899304-425eee4c7efc?w=800&h=600&fit=crop',
    'Monash University Malaysia' => 'https://images.unsplash.com/photo-1498243691581-b145c3f54a5a?w=800&h=600&fit=crop',
    'University of Nottingham Malaysia' => 'https://images.unsplash.com/photo-1503676260728-1c00da094a0b?w=800&h=600&fit=crop',
    'Taylor\'s University' => 'https://images.unsplash.com/photo-1541339907198-e08756dedf3f?w=800&h=600&fit=crop'
];

$stmt = $conn->prepare("UPDATE universities SET logo_url = ? WHERE name = ?");

$updated = 0;
foreach ($university_images as $name => $image_url) {
    $stmt->bind_param("ss", $image_url, $name);
    if ($stmt->execute()) {
        $updated++;
        echo "Updated: $name\n";
    } else {
        echo "Error updating $name: " . $conn->error . "\n";
    }
}

echo "\nCompleted! Updated $updated universities with images.\n";
