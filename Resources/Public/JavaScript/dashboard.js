const translate = (text, values = {}) => Object.entries(values).reduce((result, [key, value]) => result.replaceAll(key, String(value)), text);
  const bytes = (value) => {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
    const number = Math.max(0, Number(value));
    if (number >= 1048576) return `${(number / 1048576).toFixed(1)} MB`;
    if (number >= 1024) return `${parseFloat((number / 1024).toFixed(1))} KB`;
    return `${Math.round(number)} B`;
  };

  document.querySelectorAll('[data-viewmend-dashboard]').forEach((root) => {
        root.querySelectorAll('[data-viewmend-enhance]').forEach((item) => { item.hidden = false; });
        root.querySelectorAll('[data-viewmend-filter-panel]').forEach((panel) => {
          const search = panel.querySelector('[data-finding-search]');
          let filter = 'all';
          const apply = () => {
            const query = search ? search.value.trim().toLocaleLowerCase() : '';
            let count = 0;
            panel.querySelectorAll('[data-filter-row]').forEach((row) => {
              const text = (row.dataset.findingSearchText || row.textContent).toLocaleLowerCase();
              row.hidden = (filter !== 'all' && row.dataset.filterRow !== filter) || !text.includes(query);
              if (!row.hidden) count += Number(row.dataset.findingCount || 1);
            });
            panel.querySelector('[data-filter-empty]').hidden = count > 0 || (filter === 'all' && query === '');
            panel.querySelector('[data-filter-count]').textContent = panel.dataset.viewmendFilterPanel === 'issues'
              ? translate('Showing @count of @total findings', {'@count': count, '@total': panel.dataset.total})
              : translate('@count changed resources shown', {'@count': count});
          };
          panel.querySelectorAll('[data-filter]').forEach((button) => {
            button.addEventListener('click', () => {
              filter = button.dataset.filter;
              panel.querySelectorAll('[data-filter]').forEach((item) => item.setAttribute('aria-pressed', String(item === button)));
              apply();
            });
          });
          if (search) search.addEventListener('input', apply);
        });

        root.querySelector('[data-viewmend-periods] button')?.setAttribute('aria-pressed', 'true');
        root.querySelectorAll('[data-viewmend-periods] button').forEach((button) => {
          button.addEventListener('click', () => {
            root.querySelector('[data-viewmend-periods] button')?.setAttribute('aria-pressed', 'true');
        root.querySelectorAll('[data-viewmend-periods] button').forEach((item) => item.setAttribute('aria-pressed', String(item === button)));
            root.querySelectorAll('[data-period-panel]').forEach((panel) => { panel.hidden = panel.dataset.periodPanel !== button.dataset.period; });
          });
        });

        root.querySelectorAll('[data-viewmend-performance]').forEach((section) => {
          const buttons = section.querySelectorAll('[data-metric]');
          const select = (button) => {
            buttons.forEach((item) => item.setAttribute('aria-pressed', String(item === button)));
            section.querySelectorAll('[data-metric-panel]').forEach((panel) => { panel.hidden = panel.dataset.metricPanel !== button.dataset.metric; });
          };
          buttons.forEach((button) => button.addEventListener('click', () => select(button)));
          if (buttons.length) select(buttons[0]);
        });

        root.querySelectorAll('[data-viewmend-chart]').forEach((chart) => {
          const points = JSON.parse(chart.dataset.points);
          const range = chart.querySelector('[data-chart-range]');
          if (!Array.isArray(points) || !points.length || !range) return;
          const guide = chart.querySelector('[data-chart-guide]');
          const output = chart.querySelector('[data-chart-output]');
          const select = (index) => {
            const point = points[index];
            guide.setAttribute('x1', point.x);
            guide.setAttribute('x2', point.x);
            guide.removeAttribute('hidden');
            range.value = index;
            output.textContent = `${point.date} · ${point.reading}`;
            range.setAttribute('aria-valuetext', output.textContent);
          };
          range.addEventListener('input', () => select(Number(range.value)));
          const svg = chart.querySelector('svg');
          svg.addEventListener('pointermove', (event) => {
            if (event.pointerType === 'touch') return;
            const matrix = svg.getScreenCTM();
            if (!matrix) return;
            const cursor = svg.createSVGPoint();
            cursor.x = event.clientX;
            cursor.y = event.clientY;
            const x = cursor.matrixTransform(matrix.inverse()).x;
            let closest = 0;
            points.forEach((point, index) => { if (Math.abs(point.x - x) < Math.abs(points[closest].x - x)) closest = index; });
            select(closest);
          });
          select(points.length - 1);
        });

        const inspector = root.querySelector('[data-resource-inspector]');
        if (!inspector) return;
        const body = inspector.querySelector('[data-resource-body]');
        const status = inspector.querySelector('[data-resource-status]');
        const note = inspector.querySelector('[data-resource-note]');
        const search = inspector.querySelector('[data-resource-search]');
        const title = inspector.querySelector('[data-resource-title]');
        let controller;
        let activeButton;
        let generation = 0;

        root.querySelectorAll('[data-resource-url]').forEach((button) => {
          button.addEventListener('click', async () => {
            if (!button.dataset.resourceUrl) return;
            if (controller) controller.abort();
            controller = new AbortController();
            const requestController = controller;
            const current = ++generation;
            activeButton = button;
            root.querySelectorAll('[data-resource-url]').forEach((item) => item.setAttribute('aria-expanded', String(item === button)));
            inspector.hidden = false;
            inspector.setAttribute('aria-busy', 'true');
            title.textContent = translate('@type resources', {'@type': button.dataset.resourceLabel});
            status.textContent = translate('Loading stored requests…');
            body.replaceChildren();
            note.textContent = '';
            search.value = '';
            search.disabled = true;
            title.focus({preventScroll: true});
            inspector.scrollIntoView({block: 'nearest', behavior: 'instant'});
            const timeout = window.setTimeout(() => requestController.abort(), 12000);
            try {
              const response = await fetch(button.dataset.resourceUrl, {credentials: 'same-origin', signal: requestController.signal, headers: {'Accept': 'application/json'}});
              const payload = await response.json();
              if (current !== generation) return;
              if (!response.ok || !payload.success) throw new Error(payload.message || translate('Requests are unavailable. Select a type to try again.'));
              const items = Array.isArray(payload.data.items) ? payload.data.items : [];
              const summary = payload.data.summary || {};
              items.forEach((request) => {
                const row = document.createElement('tr');
                const resource = row.insertCell();
                const name = document.createElement('strong');
                name.textContent = request.url || translate('Unknown resource');
                resource.append(name);
                const mime = document.createElement('small');
                mime.textContent = request.mime_type || translate('Unknown type');
                resource.append(mime);
                if (request.third_party || request.render_blocking) {
                  const flags = document.createElement('small');
                  flags.textContent = [request.third_party ? translate('Third party') : '', request.render_blocking ? translate('Render blocking') : ''].filter(Boolean).join(' · ');
                  resource.append(flags);
                }
                row.insertCell().textContent = request.status_code ?? '—';
                row.insertCell().textContent = bytes(request.transferred_bytes);
                row.insertCell().textContent = request.duration_ms === null || request.duration_ms === undefined ? '—' : `${Math.round(Number(request.duration_ms))} ms`;
                body.append(row);
              });
              status.textContent = translate('@count stored requests · @bytes', {'@count': items.length, '@bytes': bytes(summary.transferred_bytes)});
              search.disabled = items.length === 0;
              note.textContent = summary.truncated
                ? translate('@stored of @reported reported requests were retained in this snapshot.', {'@stored': summary.stored_requests, '@reported': summary.reported_requests})
                : (items.length ? translate('All retained requests for this type are shown.') : translate('No retained requests for this type. Transfer totals may include bytes not attributed to individual requests.'));
            }
            catch (error) {
              if (current === generation) {
                status.textContent = error.name === 'AbortError' ? translate('The request timed out. Select a resource type to try again.') : (error instanceof SyntaxError ? translate('Requests are unavailable. Reload the page and try again.') : error.message);
              }
            }
            finally {
              window.clearTimeout(timeout);
              if (current === generation) inspector.setAttribute('aria-busy', 'false');
            }
          });
        });
        inspector.querySelector('[data-resource-close]').addEventListener('click', () => {
          generation += 1;
          if (controller) controller.abort();
          inspector.hidden = true;
          if (activeButton) {
            activeButton.setAttribute('aria-expanded', 'false');
            activeButton.focus({preventScroll: true});
          }
        });
        inspector.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') inspector.querySelector('[data-resource-close]').click();
        });
        search.addEventListener('input', () => {
          const query = search.value.trim().toLowerCase();
          let count = 0;
          body.querySelectorAll('tr').forEach((row) => {
            row.hidden = !row.textContent.toLowerCase().includes(query);
            if (!row.hidden) count += 1;
          });
          status.textContent = translate('@count matching requests', {'@count': count});
        });
  });
