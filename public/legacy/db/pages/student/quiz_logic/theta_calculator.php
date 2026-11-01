<?php
class ThetaCalculator {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    public function getDifficultyRating($difficulty) {
        switch (strtolower($difficulty)) {
            case 'easy': return 900;
            case 'medium': return 1025;
            case 'hard': return 1175;
            default: return 1025;
        }
    }
    
    public function getStudentPValue($student_id) {
        $stmt = $this->conn->prepare("SELECT p_value FROM students WHERE student_id = ?");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result['p_value'] ?? 0.5;
    }
    
    public function calculate($theta_old, $difficulty, $is_correct, $p_value = 0.5) {
        $K = 25;
        
        // Ensure theta_old is a valid number
        if (!is_numeric($theta_old)) {
            $theta_old = 0;
        }
        
        // Calculate expected score (probability of correct answer)
        $expected = 1 / (1 + pow(10, ($difficulty - $theta_old) / 400));
        
        $actual = $is_correct ? 1 : 0;
        
        // Calculate theta change with P-value adjustment
        $change = $K * ($actual - $expected) * $p_value;
        
        // Cap the change to prevent extreme swings
        $change = max(-50, min(50, $change));
        
        $new_theta = $theta_old + $change;
        
        return [
            'new_theta' => $new_theta,
            'change' => $change,
            'expected' => $expected,
            'actual' => $actual,
            'p_used' => $p_value
        ];
    }
    
    public function updatePValue($student_id, $performance_history, $current_p) {
        // Need at least 5 recent attempts to adjust P-value
        if (count($performance_history) < 5) {
            return $current_p;
        }

        // Get last 5 attempts
        $last_five = array_slice($performance_history, -5);
        $correct_count = array_sum($last_five);

        // Adjust P-value based on performance
        if ($correct_count >= 4) { // 80-100% correct
            // Doing well - decrease P (learn faster with smaller adjustments)
            $new_p = max(0.2, $current_p - 0.05);
        } elseif ($correct_count <= 1) { // 0-20% correct
            // Struggling - increase P (learn slower with smaller adjustments)
            $new_p = min(0.8, $current_p + 0.05);
        } else { // 2-3 correct (40-60%)
            // Average performance - move toward 0.5
            if ($current_p > 0.5) {
                $new_p = max(0.5, $current_p - 0.03);
            } else {
                $new_p = min(0.5, $current_p + 0.03);
            }
        }

        // Update in database
        $stmt = $this->conn->prepare("UPDATE students SET p_value = ? WHERE student_id = ?");
        $stmt->bind_param("di", $new_p, $student_id);
        $stmt->execute();
        $stmt->close();
        
        return $new_p;
    }
    
    public function getPerformanceHistory($student_id, $limit = 20) {
        // Get recent performance history, excluding current quiz if needed
        $stmt = $this->conn->prepare("
            SELECT is_correct, timestamp 
            FROM quiz_logs 
            WHERE student_id = ? 
            AND is_correct IS NOT NULL
            ORDER BY timestamp DESC 
            LIMIT ?
        ");
        $stmt->bind_param("ii", $student_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $history = [];
        while ($row = $result->fetch_assoc()) {
            $history[] = (int)$row['is_correct'];
        }
        $stmt->close();
        
        return array_reverse($history); // Return oldest to newest
    }
    
    public function updateLearnerCategory($student_id, $theta) {
        // Get theta distribution of all students (excluding archived)
        $stmt = $this->conn->prepare("
            SELECT theta 
            FROM students 
            WHERE archived = 0 
            AND theta IS NOT NULL
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        
        $theta_values = [];
        while ($row = $result->fetch_assoc()) {
            $theta_values[] = $row['theta'];
        }
        
        if (empty($theta_values) || count($theta_values) < 5) {
            // Not enough data to determine categories
            return [
                'category' => 'Average Learner',
                'assigned_set' => 'Medium',
                'mean' => 1000,
                'std_dev' => 100
            ];
        }
        
        // Calculate statistics
        $mean = array_sum($theta_values) / count($theta_values);
        $variance = 0.0;
        foreach ($theta_values as $t) {
            $variance += pow($t - $mean, 2);
        }
        $std_dev = sqrt($variance / count($theta_values));
        
        // Prevent division by zero
        if ($std_dev == 0) {
            $std_dev = 100; // Default value
        }

        // Determine category based on standard deviations from mean
        if ($theta <= $mean - 0.5 * $std_dev) {
            $category = 'Slow Learner';
            $assigned_set = 'Easy';
        } elseif ($theta >= $mean + 0.5 * $std_dev) {
            $category = 'Fast Learner';
            $assigned_set = 'Hard';
        } else {
            $category = 'Average Learner';
            $assigned_set = 'Medium';
        }
        
        // Update database
        $stmt = $this->conn->prepare("
            UPDATE students 
            SET learner_category = ?, assigned_set = ?
            WHERE student_id = ?
        ");
        $stmt->bind_param("ssi", $category, $assigned_set, $student_id);
        $stmt->execute();
        $stmt->close();
        
        return [
            'category' => $category,
            'assigned_set' => $assigned_set,
            'mean' => $mean,
            'std_dev' => $std_dev
        ];
    }

    public function updateQuizLogTheta($student_id, $quiz_id, $question_id, $old_theta, $new_theta) {
        // Update the most recent quiz_logs entry with theta values
        // This is called AFTER the initial log has been created
        $stmt = $this->conn->prepare("
            UPDATE quiz_logs 
            SET old_theta = ?, new_theta = ?
            WHERE student_id = ? 
            AND quiz_id = ?
            AND question_id = ?
            AND is_correct = 1
            ORDER BY timestamp DESC
            LIMIT 1
        ");
        $stmt->bind_param("ddiii", $old_theta, $new_theta, $student_id, $quiz_id, $question_id);
        $stmt->execute();
        $stmt->close();
    }

    public function updateStudentTheta($student_id, $new_theta) {
        $stmt = $this->conn->prepare("
            UPDATE students 
            SET theta = ? 
            WHERE student_id = ?
        ");
        $stmt->bind_param("di", $new_theta, $student_id);
        $stmt->execute();
        $stmt->close();
    }

    public function getStudentTheta($student_id) {
        $stmt = $this->conn->prepare("SELECT theta FROM students WHERE student_id = ?");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (float)($result['theta'] ?? 0.0);
    }

    public function getQuestionDifficulty($question_id) {
        $stmt = $this->conn->prepare("SELECT difficulty FROM questions WHERE question_id = ?");
        $stmt->bind_param("i", $question_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $this->getDifficultyRating($result['difficulty'] ?? 'medium');
    }

    public function processAnswer($student_id, $quiz_id, $question_id, $is_correct) {
        // Don't process theta for wrong answers
        if (!$is_correct) {
            return [
                'old_theta' => $this->getStudentTheta($student_id),
                'new_theta' => $this->getStudentTheta($student_id),
                'theta_change' => 0,
                'new_p_value' => $this->getStudentPValue($student_id),
                'category_info' => null,
                'expected' => 0,
                'actual' => 0
            ];
        }

        $current_theta = $this->getStudentTheta($student_id);
        $current_p_value = $this->getStudentPValue($student_id);

        $difficulty = $this->getQuestionDifficulty($question_id);

        $calculation = $this->calculate($current_theta, $difficulty, $is_correct, $current_p_value);

        // Update student's theta
        $this->updateStudentTheta($student_id, $calculation['new_theta']);
        
        // Update quiz_logs with theta values (entry already exists from quiz_processor)
        $this->updateQuizLogTheta($student_id, $quiz_id, $question_id, $current_theta, $calculation['new_theta']);

        // Update P-value based on performance
        $performance_history = $this->getPerformanceHistory($student_id);
        $new_p_value = $this->updatePValue($student_id, $performance_history, $current_p_value);

        // Update learner category
        $category_info = $this->updateLearnerCategory($student_id, $calculation['new_theta']);
        
        return [
            'old_theta' => $current_theta,
            'new_theta' => $calculation['new_theta'],
            'theta_change' => $calculation['change'],
            'new_p_value' => $new_p_value,
            'category_info' => $category_info,
            'expected' => $calculation['expected'],
            'actual' => $calculation['actual']
        ];
    }
}
?>