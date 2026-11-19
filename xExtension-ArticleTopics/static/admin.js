const initArticleTopics = () => {
	const rows = document.getElementById('topic-rows');
	const parsePhrases = (text, weighted) => {
		const values = text.split(',').map(x => x.trim()).filter(Boolean);
		if (!weighted) return values;
		const out = {};
		for (const value of values) { const match = value.match(/^(.*?):\s*([0-9]+(?:\.[0-9]+)?)$/); out[match ? match[1].trim() : value] = match ? Number(match[2]) : 1; }
		return out;
	};
	document.getElementById('topic-add').addEventListener('click', () => {
		const tr = document.createElement('tr'); tr.innerHTML = '<td><input class="topic-name"></td><td><input class="topic-enabled" type="checkbox" checked></td><td><input class="topic-description"></td><td><textarea class="topic-positive" rows="2"></textarea></td><td><textarea class="topic-negative" rows="2"></textarea></td><td><input class="topic-threshold" type="number" min="0" step="0.1" value="2"></td><td><button type="button" class="btn topic-delete">Delete</button></td>'; rows.appendChild(tr);
	});
	rows.addEventListener('click', e => { if (e.target.classList.contains('topic-delete')) e.target.closest('tr').remove(); });
	document.getElementById('article-topics-form').addEventListener('submit', e => {
		if (e.submitter && e.submitter.name === 'admin_action') return;
		const topics = [...rows.querySelectorAll('tr')].map(row => ({name:row.querySelector('.topic-name').value.trim(),enabled:row.querySelector('.topic-enabled').checked,description:row.querySelector('.topic-description').value,positive_keywords:parsePhrases(row.querySelector('.topic-positive').value,true),negative_keywords:parsePhrases(row.querySelector('.topic-negative').value,false),threshold:Number(row.querySelector('.topic-threshold').value || 2),exclusions:JSON.parse(row.dataset.exclusions || '[]')})).filter(t => t.name);
		document.getElementById('taxonomy_json').value = JSON.stringify(topics);
	});
}; if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initArticleTopics); } else { initArticleTopics(); }