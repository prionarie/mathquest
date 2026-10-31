function loadNextQuestion() {
    fetch("get_question.php")
    .then(res => res.json())
    .then(data => {
        const questionDiv = document.getElementById("question");
        const optionsDiv = document.getElementById("options");
        optionsDiv.innerHTML = "";

        if (!data || !data.id) {
            questionDiv.innerText = "🎉 Quiz Complete!";
            return;
        }

        questionDiv.innerText = data.question;

        data.options.forEach(opt => {
            let btn = document.createElement("button");
            btn.innerText = opt;
            btn.disabled = false;
            btn.onclick = () => {
                Array.from(optionsDiv.children).forEach(b => b.disabled = true);
                submitAnswer(data.id, opt);
            };
            optionsDiv.appendChild(btn);
        });
    })
    .catch(err => console.error("Error loading question:", err));
}

function submitAnswer(question_id, choice) {
    const formData = new URLSearchParams();
    formData.append("question_id", question_id);
    formData.append("answer", choice);

    fetch("submit_answer.php", { method: "POST", body: formData })
        .then(res => res.json())
        .then(result => {

            if (!result.correct) {
                let msg = "❌ Wrong!";
                if (result.hint && result.hint.trim() !== "") {
                    msg += " Hint: " + result.hint;
                } else {
                    msg += " Try again!";
                }
                alert(msg);
            } else {
                alert("✅ Correct!");
            }

            updateDashboard(result);
            if (result.correct) {
                loadNextQuestion();
            }
        })
        .catch(err => console.error("Error submitting answer:", err));
}

function updateDashboard(data) {
    document.getElementById('points').innerText = data.points;
    document.getElementById('level').innerText = data.level;

    if (data.theta !== undefined)
        document.getElementById('theta').innerText = Number(data.theta).toFixed(2);

    if (data.category)
        document.getElementById('category').innerText = data.category;

    if (data.mean_theta !== undefined && data.std_dev_theta !== undefined) {
        console.log(
            "Class Mean θ:", data.mean_theta.toFixed(2),
            "σ:", data.std_dev_theta.toFixed(2)
        );
    }

    if (data.badges && data.badges.length > 0) {
        data.badges.forEach(showBadgeToast);
    }
}

if (result.badges && result.badges.length > 0) {
    result.badges.forEach(showBadgeToast);
}

loadNextQuestion();
