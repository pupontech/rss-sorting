(() => {
	const parsePhrases = (text, weighted) => {
		const phrases = text.split(',').map(phrase => phrase.trim()).filter(Boolean);
		if (!weighted) return phrases;

		const weights = Object.create(null);
		for (const phrase of phrases) {
			const match = phrase.match(/^(.*?):\s*([0-9]+(?:\.[0-9]+)?)$/);
			weights[match ? match[1].trim() : phrase] = match ? Number(match[2]) : 1;
		}
		return weights;
	};

	// The Extensions page inserts and replaces the settings form in its slider.
	document.addEventListener('click', event => {
		const button = event.target.closest('#article-topics-form button');
		if (!button) return;

		if (button.id === 'topic-add') {
			const row = document.createElement('tr');
			row.innerHTML = '<td><input class="topic-name"></td><td><input class="topic-enabled" type="checkbox" checked></td><td><input class="topic-description"></td><td><textarea class="topic-positive" rows="2"></textarea></td><td><textarea class="topic-negative" rows="2"></textarea></td><td><input class="topic-threshold" type="number" min="0" step="0.1" value="2"></td><td><button type="button" class="btn topic-delete">Delete</button></td>';
			button.form.querySelector('#topic-rows').appendChild(row);
		} else if (button.classList.contains('topic-delete')) {
			button.closest('tr').remove();
		}
	});

	document.addEventListener('submit', event => {
		const form = event.target;
		if (form.id !== 'article-topics-form' || event.submitter?.name === 'admin_action') return;

		const topics = [...form.querySelectorAll('#topic-rows tr')].map(row => ({
			name: row.querySelector('.topic-name').value.trim(),
			enabled: row.querySelector('.topic-enabled').checked,
			description: row.querySelector('.topic-description').value,
			positive_keywords: parsePhrases(row.querySelector('.topic-positive').value, true),
			negative_keywords: parsePhrases(row.querySelector('.topic-negative').value, false),
			threshold: Number(row.querySelector('.topic-threshold').value || 2),
			exclusions: JSON.parse(row.dataset.exclusions || '[]'),
		})).filter(topic => topic.name);
		form.querySelector('#taxonomy_json').value = JSON.stringify(topics);
	}, true);
})();
