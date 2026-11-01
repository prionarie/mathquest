<?php
$recent_activities = [];

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $intervals = [
        'y' => $diff->y,
        'm' => $diff->m,
        'd' => $diff->d,
        'h' => $diff->h,
        'i' => $diff->i,
        's' => $diff->s,
    ];
    $intervals['w'] = floor($intervals['d'] / 7);
    $intervals['d'] -= $intervals['w'] * 7;

    $string = [
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    ];

    foreach ($string as $k => &$v) {
        if ($intervals[$k]) {
            $v = $intervals[$k] . ' ' . $v . ($intervals[$k] > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

/* 1. Teacher creates sections */
$query_sections = "
    SELECT section_name, school_year, date_created
    FROM sections
    WHERE teacher_id = ?
    ORDER BY date_created DESC
    LIMIT 5
";
$stmt = $conn->prepare($query_sections);
$stmt->bind_param("i", $_SESSION['teacher_id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recent_activities[] = [
        'icon' => 'fa-chalkboard',
        'title' => 'New Section Created',
        'desc' => htmlspecialchars($row['section_name'] . ' (' . $row['school_year'] . ')'),
        'time' => time_elapsed_string($row['date_created']),
        'timestamp' => $row['date_created']
    ];
}
$stmt->close();

/* 2. Teacher adds students */
$query_students = "
    SELECT s.first_name, s.last_name, sec.section_name, s.date_created
    FROM students s
    JOIN sections sec ON s.section_id = sec.section_id
    WHERE sec.teacher_id = ?
    ORDER BY s.date_created DESC
    LIMIT 5
";
$stmt = $conn->prepare($query_students);
$stmt->bind_param("i", $_SESSION['teacher_id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recent_activities[] = [
        'icon' => 'fa-user-plus',
        'title' => 'New Student Added',
        'desc' => htmlspecialchars(
            $row['first_name'] . ' ' . $row['last_name'] . ' added to ' . $row['section_name']
        ),
        'time' => time_elapsed_string($row['date_created']),
        'timestamp' => $row['date_created']
    ];
}
$stmt->close();

/* 3. Teacher uploads materials */
$query_materials = "
    SELECT title, type, uploaded_at
    FROM learning_materials
    WHERE teacher_id = ?
    ORDER BY uploaded_at DESC
    LIMIT 5
";
$stmt = $conn->prepare($query_materials);
$stmt->bind_param("i", $_SESSION['teacher_id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recent_activities[] = [
        'icon' => 'fa-upload',
        'title' => 'New Material Uploaded',
        'desc' => htmlspecialchars($row['title'] . ' (' . $row['type'] . ')'),
        'time' => time_elapsed_string($row['uploaded_at']),
        'timestamp' => $row['uploaded_at']
    ];
}
$stmt->close();

/* 4. Teacher creates quizzes */
$query_quizzes = "
    SELECT quiz_type, week, date_created
    FROM quizzes
    WHERE teacher_id = ?
    ORDER BY date_created DESC
    LIMIT 5
";
$stmt = $conn->prepare($query_quizzes);
$stmt->bind_param("i", $_SESSION['teacher_id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recent_activities[] = [
        'icon' => 'fa-file-alt',
        'title' => 'New Quiz Created',
        'desc' => ucfirst($row['quiz_type']) . ' quiz for week ' . $row['week'],
        'time' => time_elapsed_string($row['date_created']),
        'timestamp' => $row['date_created']
    ];
}
$stmt->close();

/* 5. Students take quizzes */
$query_taken = "
    SELECT st.first_name, st.last_name, q.week, sq.score, sq.date_taken
    FROM student_quizzes sq
    JOIN students st ON sq.student_id = st.student_id
    JOIN quizzes q ON sq.quiz_id = q.quiz_id
    JOIN sections sec ON st.section_id = sec.section_id
    WHERE sec.teacher_id = ?
    ORDER BY sq.date_taken DESC
    LIMIT 5
";
$stmt = $conn->prepare($query_taken);
$stmt->bind_param("i", $_SESSION['teacher_id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recent_activities[] = [
        'icon' => 'fa-check',
        'title' => 'Quiz Taken',
        'desc' => htmlspecialchars(
            $row['first_name'] . ' ' . $row['last_name'] .
            ' scored ' . $row['score'] . '% in week ' . $row['week']
        ),
        'time' => time_elapsed_string($row['date_taken']),
        'timestamp' => $row['date_taken']
    ];
}
$stmt->close();

usort($recent_activities, function ($a, $b) {
    return strtotime($b['timestamp']) - strtotime($a['timestamp']);
});

$recent_activities = array_slice($recent_activities, 0, 10);

if (empty($recent_activities)) {
    $recent_activities[] = [
        'icon' => 'fa-info-circle',
        'title' => 'No recent activity',
        'desc' => '',
        'time' => ''
    ];
}
?>
