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

	async function loadModels(button) {
		const form = button.form;
		const provider = form.elements.api_url;
		const key = form.elements.api_key;
		const options = form.querySelector('#article-topics-model-options');
		const choices = options.querySelector('select');
		const status = form.querySelector('#article-topics-model-status');
		const requestedUrl = provider.value.trim();
		const requestedKey = key.value.trim();
		options.hidden = true;
		status.textContent = 'Loading models…';
		button.disabled = true;
		const stillCurrent = () => form.isConnected
			&& provider.value.trim() === requestedUrl && key.value.trim() === requestedKey;
		try {
			const endpoint = new URL(form.action);
			endpoint.searchParams.set('ajax', '1');
			const response = await fetch(endpoint, {
				method: 'POST',
				body: new URLSearchParams({
					_csrf: form.elements._csrf.value,
					admin_action: 'list_models',
					api_url: requestedUrl,
					api_key: requestedKey,
				}),
			});
			if (!response.ok) throw new Error();
			const result = await response.json();
			if (!stillCurrent()) return;
			if (result.error) {
				status.textContent = result.error;
				return;
			}
			if (!result.models.length) {
				status.textContent = 'The provider returned no models. Enter a model ID.';
				return;
			}
			choices.replaceChildren(new Option('Choose a model', ''), ...result.models.map(id => new Option(id, id)));
			choices.value = result.models.includes(form.elements.model.value) ? form.elements.model.value : '';
			options.hidden = false;
			status.textContent = 'Choose a model, then save settings.';
		} catch {
			if (stillCurrent()) status.textContent = 'Could not load models. Try again or enter a model ID.';
		} finally {
			button.disabled = false;
		}
	}

	// The Extensions page inserts and replaces the settings form in its slider.
	document.addEventListener('click', event => {
		const button = event.target.closest('#article-topics-form button');
		if (!button) return;

		if (button.id === 'article-topics-load-models') {
			void loadModels(button);
		} else if (button.id === 'topic-add') {
			const row = document.createElement('tr');
			row.innerHTML = '<td><input class="topic-name"></td><td><input class="topic-enabled" type="checkbox" checked></td><td><input class="topic-description"></td><td><textarea class="topic-positive" rows="2"></textarea></td><td><textarea class="topic-negative" rows="2"></textarea></td><td><input class="topic-threshold" type="number" min="0" step="0.1" value="2"></td><td><button type="button" class="btn topic-delete">Delete</button></td>';
			button.form.querySelector('#topic-rows').appendChild(row);
		} else if (button.classList.contains('topic-delete')) {
			button.closest('tr').remove();
		}
	});

	document.addEventListener('change', event => {
		const choices = event.target.closest('#article-topics-model-options select');
		if (choices?.value) choices.form.elements.model.value = choices.value;
	});

	document.addEventListener('input', event => {
		const field = event.target.closest('#article-topics-form input');
		if (!field) return;
		const options = field.form.querySelector('#article-topics-model-options');
		if (field.name === 'model') {
			const choices = options.querySelector('select');
			choices.value = [...choices.options].some(option => option.value === field.value) ? field.value : '';
		} else if (field.name === 'api_url' || field.name === 'api_key') {
			options.hidden = true;
			field.form.querySelector('#article-topics-model-status').textContent = '';
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
