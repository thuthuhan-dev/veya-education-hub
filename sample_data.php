<?php
require_once "db_connect.php";

// Sample data for universities and programs
$universities_data = [
    // Thailand Universities (10)
    [
        'country_id' => 1,
        'name' => 'Chulalongkorn University',
        'city' => 'Bangkok',
        'website_url' => 'https://www.chula.ac.th',
        'logo_url' => 'https://images.unsplash.com/photo-1562774053-701939374585?w=800&h=600&fit=crop',
        'description' => 'Thailand\'s oldest and most prestigious university, known for excellence in engineering, medicine, and arts.',
        'established_year' => 1917,
        'university_type' => 'Public'
    ],
    [
        'country_id' => 1,
        'name' => 'Thammasat University',
        'city' => 'Bangkok',
        'website_url' => 'https://www.tu.ac.th',
        'logo_url' => 'https://images.unsplash.com/photo-1541339907198-e08756dedf3f?w=800&h=600&fit=crop',
        'description' => 'Leading Thai university with strong programs in law, economics, and social sciences.',
        'established_year' => 1934,
        'university_type' => 'Public'
    ],
    [
        'country_id' => 1,
        'name' => 'Mahidol University',
        'city' => 'Bangkok',
        'website_url' => 'https://www.mahidol.ac.th',
        'logo_url' => 'https://images.unsplash.com/photo-1523050854058-8df90110c9f1?w=800&h=600&fit=crop',
        'description' => 'Top-ranked medical and health sciences university with excellent research facilities.',
        'established_year' => 1943,
        'university_type' => 'Public'
    ],
    [
        'country_id' => 1,
        'name' => 'Kasetsart University',
        'city' => 'Bangkok',
        'website_url' => 'https://www.ku.ac.th',
        'logo_url' => 'https://images.unsplash.com/photo-1564981797816-1043664bf78d?w=800&h=600&fit=crop',
        'description' => 'Premier agricultural and technological university with strong engineering programs.',
        'established_year' => 1943,
        'university_type' => 'Public'
    ],
    [
        'country_id' => 1,
        'name' => 'King Mongkut\'s University of Technology Thonburi',
        'city' => 'Bangkok',
        'website_url' => 'https://www.kmutt.ac.th',
        'logo_url' => 'https://images.unsplash.com/photo-1581093458791-9f3c3900df4b?w=800&h=600&fit=crop',
        'description' => 'Leading technological university known for engineering and innovation.',
        'established_year' => 1960,
        'university_type' => 'Public'
    ],
    [
        'country_id' => 1,
        'name' => 'Bangkok University',
        'city' => 'Bangkok',
        'website_url' => 'https://www.bu.ac.th',
        'logo_url' => 'https://images.unsplash.com/photo-1568605117036-5fe5e7bab0b7?w=800&h=600&fit=crop',
        'description' => 'Private university with strong business and communication programs.',
        'established_year' => 1962,
        'university_type' => 'Private'
    ],
    [
        'country_id' => 1,
        'name' => 'Assumption University',
        'city' => 'Bangkok',
        'website_url' => 'https://www.au.edu',
        'logo_url' => 'https://images.unsplash.com/photo-1571260899304-425eee4c7efc?w=800&h=600&fit=crop',
        'description' => 'International private university with English-medium instruction.',
        'established_year' => 1969,
        'university_type' => 'Private'
    ],
    [
        'country_id' => 1,
        'name' => 'Chiang Mai University',
        'city' => 'Chiang Mai',
        'website_url' => 'https://www.cmu.ac.th',
        'logo_url' => 'https://images.unsplash.com/photo-1498243691581-b145c3f54a5a?w=800&h=600&fit=crop',
        'description' => 'Northern Thailand\'s leading university with strong programs in Thai studies and environmental sciences.',
        'established_year' => 1964,
        'university_type' => 'Public'
    ],
    [
        'country_id' => 1,
        'name' => 'Prince of Songkla University',
        'city' => 'Hat Yai',
        'website_url' => 'https://www.psu.ac.th',
        'logo_url' => 'https://images.unsplash.com/photo-1503676260728-1c00da094a0b?w=800&h=600&fit=crop',
        'description' => 'Southern Thailand\'s major university with excellent marine science and engineering programs.',
        'established_year' => 1967,
        'university_type' => 'Public'
    ],
    [
        'country_id' => 1,
        'name' => 'Asian Institute of Technology',
        'city' => 'Pathum Thani',
        'website_url' => 'https://www.ait.ac.th',
        'logo_url' => 'https://images.unsplash.com/photo-1559136555-9303baea8ebd?w=800&h=600&fit=crop',
        'description' => 'International postgraduate institute focusing on engineering, environment, and management.',
        'established_year' => 1959,
        'university_type' => 'Private'
    ],
    
    // Singapore Universities (10)
    [
        'country_id' => 2,
        'name' => 'National University of Singapore',
        'city' => 'Singapore',
        'website_url' => 'https://www.nus.edu.sg',
        'logo_url' => 'https://images.unsplash.com/photo-1523580494863-6f3031224c94?w=800&h=600&fit=crop',
        'description' => 'Singapore\'s flagship university, consistently ranked among Asia\'s top universities.',
        'established_year' => 1905,
        'university_type' => 'Public'
    ],
    [
        'country_id' => 2,
        'name' => 'Nanyang Technological University',
        'city' => 'Singapore',
        'website_url' => 'https://www.ntu.edu.sg',
        'logo_url' => 'https://images.unsplash.com/photo-1552664730-d307ca884978?w=800&h=600&fit=crop',
        'description' => 'World-class research university known for engineering and business programs.',
        'established_year' => 1991,
        'university_type' => 'Public'
    ],
    [
        'country_id' => 2,
        'name' => 'Singapore Management University',
        'city' => 'Singapore',
        'website_url' => 'https://www.smu.edu.sg',
        'logo_url' => 'https://images.unsplash.com/photo-1544531586-fde5298cdd40?w=800&h=600&fit=crop',
        'description' => 'Specialized university focusing on business, management, and law.',
        'established_year' => 2000,
        'university_type' => 'Public'
    ],
    [
        'country_id' => 2,
        'name' => 'Singapore University of Technology and Design',
        'city' => 'Singapore',
        'website_url' => 'https://www.sutd.edu.sg',
        'logo_url' => 'https://images.unsplash.com/photo-1519389950473-47ba0277781c?w=800&h=600&fit=crop',
        'description' => 'Innovative university focusing on technology and design with multidisciplinary approach.',
        'established_year' => 2009,
        'university_type' => 'Public'
    ],
    [
        'country_id' => 2,
        'name' => 'Singapore Institute of Technology',
        'city' => 'Singapore',
        'website_url' => 'https://www.singaporetech.edu.sg',
        'logo_url' => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?w=800&h=600&fit=crop',
        'description' => 'Applied learning university with strong industry partnerships.',
        'established_year' => 2009,
        'university_type' => 'Public'
    ],
    [
        'country_id' => 2,
        'name' => 'INSEAD Asia Campus',
        'city' => 'Singapore',
        'website_url' => 'https://www.insead.edu',
        'logo_url' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=800&h=600&fit=crop',
        'description' => 'Leading international business school with world-renowned MBA program.',
        'established_year' => 1957,
        'university_type' => 'Private'
    ],
    [
        'country_id' => 2,
        'name' => 'Singapore University of Social Sciences',
        'city' => 'Singapore',
        'website_url' => 'https://www.suss.edu.sg',
        'logo_url' => 'https://images.unsplash.com/photo-1524178232363-1fb2b075b955?w=800&h=600&fit=crop',
        'description' => 'University focusing on social sciences and lifelong learning.',
        'established_year' => 2005,
        'university_type' => 'Public'
    ],
    [
        'country_id' => 2,
        'name' => 'LASALLE College of the Arts',
        'city' => 'Singapore',
        'website_url' => 'https://www.lasalle.edu.sg',
        'logo_url' => 'https://images.unsplash.com/photo-1513364776144-60967b0f800f?w=800&h=600&fit=crop',
        'description' => 'Specialized institution for contemporary arts and design education.',
        'established_year' => 1984,
        'university_type' => 'Private'
    ],
    [
        'country_id' => 2,
        'name' => 'Nanyang Academy of Fine Arts',
        'city' => 'Singapore',
        'website_url' => 'https://www.nafa.edu.sg',
        'logo_url' => 'https://images.unsplash.com/photo-1460661419201-fd4cecdf8a8b?w=800&h=600&fit=crop',
        'description' => 'Premier arts institution with comprehensive fine arts programs.',
        'established_year' => 1938,
        'university_type' => 'Private'
    ],
    [
        'country_id' => 2,
        'name' => 'James Cook University Singapore',
        'city' => 'Singapore',
        'website_url' => 'https://www.jcu.edu.sg',
        'logo_url' => 'https://images.unsplash.com/photo-1541339907198-e08756dedf3f?w=800&h=600&fit=crop',
        'description' => 'Australian university campus offering business, psychology, and IT programs.',
        'established_year' => 2003,
        'university_type' => 'Private'
    ],
    
    // Malaysia Universities (10)
    [
        'country_id' => 3,
        'name' => 'University of Malaya',
        'city' => 'Kuala Lumpur',
        'website_url' => 'https://www.um.edu.my',
        'logo_url' => 'https://images.unsplash.com/photo-1564981797816-1043664bf78d?w=800&h=600&fit=crop',
        'description' => 'Malaysia\'s oldest and most prestigious university, ranked among Asia\'s top institutions.',
        'established_year' => 1905,
        'university_type' => 'Public'
    ],
    [
        'country_id' => 3,
        'name' => 'Universiti Teknologi Malaysia',
        'city' => 'Johor Bahru',
        'website_url' => 'https://www.utm.my',
        'logo_url' => 'https://images.unsplash.com/photo-1581093458791-9f3c3900df4b?w=800&h=600&fit=crop',
        'description' => 'Leading technological university with strong engineering and research programs.',
        'established_year' => 1904,
        'university_type' => 'Public'
    ],
    [
        'country_id' => 3,
        'name' => 'Universiti Sains Malaysia',
        'city' => 'Penang',
        'website_url' => 'https://www.usm.my',
        'logo_url' => 'https://images.unsplash.com/photo-1523050854058-8df90110c9f1?w=800&h=600&fit=crop',
        'description' => 'Research university known for sciences, medical, and pharmaceutical programs.',
        'established_year' => 1969,
        'university_type' => 'Public'
    ],
    [
        'country_id' => 3,
        'name' => 'Universiti Kebangsaan Malaysia',
        'city' => 'Bangi',
        'website_url' => 'https://www.ukm.my',
        'logo_url' => 'https://images.unsplash.com/photo-1562774053-701939374585?w=800&h=600&fit=crop',
        'description' => 'National university with strong programs in Islamic studies and sciences.',
        'established_year' => 1970,
        'university_type' => 'Public'
    ],
    [
        'country_id' => 3,
        'name' => 'Universiti Putra Malaysia',
        'city' => 'Serdang',
        'website_url' => 'https://www.upm.edu.my',
        'logo_url' => 'https://images.unsplash.com/photo-1559136555-9303baea8ebd?w=800&h=600&fit=crop',
        'description' => 'Premier agricultural university with excellent engineering and science programs.',
        'established_year' => 1931,
        'university_type' => 'Public'
    ],
    [
        'country_id' => 3,
        'name' => 'Universiti Teknologi MARA',
        'city' => 'Shah Alam',
        'website_url' => 'https://www.uitm.edu.my',
        'logo_url' => 'https://images.unsplash.com/photo-1568605117036-5fe5e7bab0b7?w=800&h=600&fit=crop',
        'description' => 'Largest university in Malaysia with comprehensive program offerings.',
        'established_year' => 1956,
        'university_type' => 'Public'
    ],
    [
        'country_id' => 3,
        'name' => 'International Islamic University Malaysia',
        'city' => 'Gombak',
        'website_url' => 'https://www.iium.edu.my',
        'logo_url' => 'https://images.unsplash.com/photo-1571260899304-425eee4c7efc?w=800&h=600&fit=crop',
        'description' => 'Islamic university with international standards and diverse programs.',
        'established_year' => 1983,
        'university_type' => 'Public'
    ],
    [
        'country_id' => 3,
        'name' => 'Monash University Malaysia',
        'city' => 'Bandar Sunway',
        'website_url' => 'https://www.monash.edu.my',
        'logo_url' => 'https://images.unsplash.com/photo-1498243691581-b145c3f54a5a?w=800&h=600&fit=crop',
        'description' => 'Australian university campus offering world-class education in Malaysia.',
        'established_year' => 1998,
        'university_type' => 'Private'
    ],
    [
        'country_id' => 3,
        'name' => 'University of Nottingham Malaysia',
        'city' => 'Semenyih',
        'website_url' => 'https://www.nottingham.edu.my',
        'logo_url' => 'https://images.unsplash.com/photo-1503676260728-1c00da094a0b?w=800&h=600&fit=crop',
        'description' => 'UK university campus with excellent business and engineering programs.',
        'established_year' => 2000,
        'university_type' => 'Private'
    ],
    [
        'country_id' => 3,
        'name' => 'Taylor\'s University',
        'city' => 'Subang Jaya',
        'website_url' => 'https://www.taylors.edu.my',
        'logo_url' => 'https://images.unsplash.com/photo-1541339907198-e08756dedf3f?w=800&h=600&fit=crop',
        'description' => 'Private university known for hospitality, business, and design programs.',
        'established_year' => 1969,
        'university_type' => 'Private'
    ]
];

// Insert universities
$university_stmt = $conn->prepare("INSERT INTO universities (country_id, name, city, website_url, logo_url, description, established_year, university_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

$university_map = [];
foreach ($universities_data as $uni) {
    $university_stmt->bind_param("isssssis", 
        $uni['country_id'], 
        $uni['name'], 
        $uni['city'], 
        $uni['website_url'], 
        $uni['logo_url'], 
        $uni['description'], 
        $uni['established_year'], 
        $uni['university_type']
    );
    
    if ($university_stmt->execute()) {
        $university_map[$uni['name']] = $conn->insert_id;
        echo "Added: " . $uni['name'] . "\n";
    } else {
        echo "Error adding " . $uni['name'] . ": " . $conn->error . "\n";
    }
}

// Sample programs for each university
$programs_data = [];
$degree_levels = ['Bachelor', 'Master', 'PhD'];
$fields_of_study = ['Business', 'Engineering', 'Computer Science', 'Medicine', 'Arts', 'Law', 'Education'];

foreach ($university_map as $uni_name => $uni_id) {
    // Add 3-5 programs per university
    $num_programs = rand(3, 5);
    for ($i = 0; $i < $num_programs; $i++) {
        $degree_level = $degree_levels[array_rand($degree_levels)];
        $field = $fields_of_study[array_rand($fields_of_study)];
        $duration = ($degree_level == 'PhD') ? 48 : (($degree_level == 'Master') ? 24 : 48);
        
        $programs_data[] = [
            'university_id' => $uni_id,
            'program_name' => "$degree_level in $field",
            'degree_level' => $degree_level,
            'field_of_study' => $field,
            'duration_months' => $duration,
            'language_of_instruction' => 'English',
            'description' => "Comprehensive $degree_level program in $field with practical applications and research opportunities."
        ];
    }
}

// Insert programs
$program_stmt = $conn->prepare("INSERT INTO programs (university_id, program_name, degree_level, field_of_study, duration_months, language_of_instruction, description) VALUES (?, ?, ?, ?, ?, ?, ?)");

$program_map = [];
foreach ($programs_data as $prog) {
    $university_id = $prog['university_id'];
    $program_name = $prog['program_name'];
    $degree_level = $prog['degree_level'];
    $field_of_study = $prog['field_of_study'];
    $duration_months = $prog['duration_months'];
    $language_of_instruction = $prog['language_of_instruction'];
    $description = $prog['description'];
    
    $program_stmt->bind_param("isssiss", 
        $university_id,
        $program_name,
        $degree_level,
        $field_of_study,
        $duration_months,
        $language_of_instruction,
        $description
    );
    
    if ($program_stmt->execute()) {
        $program_map[] = $conn->insert_id;
    } else {
        echo "Error adding program: " . $conn->error . "\n";
    }
}

// Add sample tuition fees for programs
$fee_stmt = $conn->prepare("INSERT INTO tuition_fees (program_id, fee_type, amount_local, local_currency_code, fee_period) VALUES (?, ?, ?, ?, ?)");

$currency_map = [1 => 'THB', 2 => 'SGD', 3 => 'MYR'];

foreach ($program_map as $program_id) {
    // Get program info to determine currency
    $prog_info = $conn->query("SELECT p.university_id, u.country_id FROM programs p JOIN universities u ON u.university_id = p.university_id WHERE p.program_id = $program_id")->fetch_assoc();
    $currency = $currency_map[$prog_info['country_id']] ?? 'USD';
    
    // Generate realistic fee amounts
    if ($currency == 'THB') {
        $amount = rand(150000, 500000);
    } elseif ($currency == 'SGD') {
        $amount = rand(25000, 80000);
    } else { // MYR
        $amount = rand(30000, 100000);
    }
    
    $fee_type = 'Tuition';
    $fee_period = 'Per Year';
    
    $fee_stmt->bind_param("isdss", 
        $program_id,
        $fee_type,
        $amount,
        $currency,
        $fee_period
    );
    
    $fee_stmt->execute();
}

// Add sample scholarships
$scholarship_data = [];
$scholarship_names = [
    'Merit Scholarship', 'International Student Grant', 'Academic Excellence Award',
    'Research Scholarship', 'Leadership Award', 'Community Service Scholarship',
    'STEM Scholarship', 'Arts and Culture Grant', 'Sports Scholarship', 'Need-Based Award'
];

foreach ($university_map as $uni_name => $uni_id) {
    // Add 1-3 scholarships per university
    $num_scholarships = rand(1, 3);
    for ($i = 0; $i < $num_scholarships; $i++) {
        $scholarship_name = $scholarship_names[array_rand($scholarship_names)];
        $prog_info = $conn->query("SELECT country_id FROM universities WHERE university_id = $uni_id")->fetch_assoc();
        
        // Direct mapping based on country_id
        if ($prog_info['country_id'] == 1) {
            $currency = 'THB';
            $amount = rand(50000, 200000);
        } elseif ($prog_info['country_id'] == 2) {
            $currency = 'SGD';
            $amount = rand(10000, 30000);
        } elseif ($prog_info['country_id'] == 3) {
            $currency = 'MYR';
            $amount = rand(15000, 40000);
        } else {
            $currency = 'USD';
            $amount = rand(10000, 50000);
        }
        
        $deadline = date('Y-m-d', strtotime('+'.rand(1, 180).' days'));
        
        $scholarship_data[] = [
            'university_id' => $uni_id,
            'name' => "$scholarship_name - " . substr($uni_name, 0, 20),
            'description' => "Financial support for outstanding students demonstrating excellence in academics and extracurricular activities.",
            'amount_local' => $amount,
            'local_currency_code' => $currency,
            'eligibility' => 'Minimum GPA 3.0, full-time enrollment',
            'application_deadline' => $deadline
        ];
    }
}

// Skip scholarships for now due to foreign key constraint issue
// TODO: Fix currency constraint and add scholarships separately
echo "\nSkipping scholarships due to currency constraint issue.\n";
echo "Scholarships will be added separately after fixing the foreign key constraint.\n";

echo "\nSample data population completed!\n";
echo "Added " . count($university_map) . " universities\n";
echo "Added " . count($program_map) . " programs\n";
echo "Scholarships skipped - will be added separately\n";
