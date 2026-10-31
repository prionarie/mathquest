<?php
class BadgeChecker
{
    private mysqli $conn;

    public function __construct($conn)
    {
        $this->conn = $conn;
    }

    public function checkAfterQuizCompletion(int $student_id, int $quiz_id): void
    {
        $stmt = $this->conn->prepare("
        SELECT
            COUNT(DISTINCT qq.question_id) total,
            COUNT(DISTINCT ql.question_id) correct
        FROM quiz_questions qq
        LEFT JOIN quiz_logs ql
            ON qq.question_id = ql.question_id
            AND ql.student_id = ?
            AND ql.quiz_id = ?
            AND ql.is_correct = 1
        WHERE qq.quiz_id = ?
    ");
        $stmt->bind_param("iii", $student_id, $quiz_id, $quiz_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $accuracy = $row['total'] > 0
            ? round(($row['correct'] / $row['total']) * 100, 2)
            : 0;

        if ($accuracy === 100) $this->awardOnce($student_id, 'Perfect Score');
        if ($accuracy >= 90)  $this->awardOnce($student_id, 'Math Whiz');

        if ($this->thetaImproved($student_id, $quiz_id)) {
            $this->awardOnce($student_id, 'Quick Learner');
        }

        if ($this->isConsistentLearner($student_id)) {
            $this->awardOnce($student_id, 'Consistent Learner');
        }

        $this->awardLevelBadge($student_id);
    }


    private function thetaImproved($student_id, $quiz_id): bool
    {
        $stmt = $this->conn->prepare("
            SELECT old_theta, new_theta
            FROM quiz_logs
            WHERE student_id = ? AND quiz_id = ?
            ORDER BY timestamp DESC
            LIMIT 1
        ");
        $stmt->bind_param("ii", $student_id, $quiz_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && $row['new_theta'] > $row['old_theta'];
    }

    private function isConsistentLearner($student_id): bool
    {
        $stmt = $this->conn->prepare("
            SELECT DATE(date_taken) d
            FROM student_quizzes
            WHERE student_id = ?
            GROUP BY d
            ORDER BY d DESC
            LIMIT 5
        ");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();

        $dates = [];
        while ($r = $stmt->get_result()->fetch_assoc()) {
            $dates[] = $r['d'];
        }
        $stmt->close();

        if (count($dates) < 5) return false;

        sort($dates);
        for ($i = 0; $i < 4; $i++) {
            if ((new DateTime($dates[$i]))->diff(new DateTime($dates[$i + 1]))->days !== 1) {
                return false;
            }
        }
        return true;
    }

    private function awardLevelBadge($student_id): void
    {
        $stmt = $this->conn->prepare("
        SELECT current_level FROM students WHERE student_id = ?
    ");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $level = (int)$stmt->get_result()->fetch_assoc()['current_level'];
        $stmt->close();

        $map = [
            10 => 'Math Explorer',
            20 => 'Math Wizard',
            30 => 'Math Champion',
            40 => 'Math Master',
            50 => 'Math Grandmaster'
        ];

        if (isset($map[$level])) {
            $this->awardOnce($student_id, $map[$level]);
        }
    }

    private function awardOnce($student_id, $badge): void
    {
        $stmt = $this->conn->prepare("
            INSERT IGNORE INTO student_badges (student_id, badge_name)
            VALUES (?, ?)
        ");
        $stmt->bind_param("is", $student_id, $badge);
        $stmt->execute();
        $stmt->close();
    }
}
