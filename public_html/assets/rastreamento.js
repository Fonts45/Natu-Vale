(() => {
  const dialog = document.getElementById('nv-tracking-dialog');
  const form = document.getElementById('nv-tracking-form');
  if (!dialog || !form) return;
  const trigger = document.querySelector('[data-tracking-open]');
  const status = dialog.querySelector('[data-tracking-status]');
  const results = dialog.querySelector('[data-tracking-results]');
  const submit = form.querySelector('[type="submit"]');
  const cnpj = form.elements.cnpj;
  let controller;
  trigger.addEventListener('click', () => dialog.showModal());
  dialog.querySelector('.nv-tracking-close').addEventListener('click', () => dialog.close());
  // Keep the consultation open until the visitor explicitly uses the X button.
  dialog.addEventListener('cancel', event => event.preventDefault());
  dialog.addEventListener('close', () => {
    controller?.abort();
    trigger.focus({preventScroll:true});
  });
  cnpj.addEventListener('input', () => {
    const value = cnpj.value.replace(/\D/g, '').slice(0, 14);
    cnpj.value = value.replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
      .replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2');
  });
  function node(tag, text, className) {
    const element = document.createElement(tag);
    element.textContent = text;
    if (className) element.className = className;
    return element;
  }
  function formatDate(value) {
    // Preserve local carrier times; don't shift a date-only ETA through UTC.
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}))?/);
    return match ? `${match[3]}/${match[2]}/${match[1]}${match[4] ? ` às ${match[4]}h${match[5]}` : ''}` : '';
  }
  const location = place => [place?.city, place?.state].filter(Boolean).join(' – ');
  function render(shipments, carrier = '') {
    results.replaceChildren();
    shipments.forEach(shipment => {
      const section = node('section', '', 'nv-tracking-shipment');
      const shipmentCarrier = shipment.carrier || carrier;
      const heading = node('h3', '', 'nv-tracking-carrier');
      const carrierKey = shipmentCarrier.toLowerCase();
      if (['jamef', 'braspress'].includes(carrierKey)) {
        const icon = node('span', '', 'nv-tracking-carrier-icon');
        const image = node('img', '');
        image.src = `./assets/icones/transportadora-${carrierKey}.png`;
        image.alt = '';
        icon.append(image);
        heading.append(icon);
      }
      heading.append(node('span', shipmentCarrier || 'Transportadora'));
      section.append(heading);
      const places = [location(shipment.origin), location(shipment.destination)].filter(Boolean);
      if (places.length) section.append(node('p', places.join(' → ')));
      const eta = formatDate(shipment.estimatedDelivery);
      if (eta) section.append(node('p', shipmentCarrier ? `Previsão informada pela ${shipmentCarrier}: ${eta}` : `Previsão de entrega: ${eta}`));
      const timeline = node('ol', '', 'nv-tracking-timeline');
      (shipment.events || []).forEach(event => {
        const item = node('li', '');
        item.append(node('strong', event.status || 'Atualização da entrega'));
        item.append(node('span', [formatDate(event.date), location(event)].filter(Boolean).join(' · ')));
        timeline.append(item);
      });
      section.append(timeline.children.length ? timeline : node('p', 'A transportadora ainda não disponibilizou eventos para esta entrega.'));
      results.append(section);
    });
  }
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (submit.disabled) return;
    const document = cnpj.value.replace(/\D/g, '');
    const nf = form.elements.nf.value.trim();
    results.replaceChildren();
    if (document.length !== 14 || !/^\d{1,9}$/.test(nf)) {
      status.textContent = 'Informe o CNPJ com 14 dígitos e o número da NF.';
      return;
    }
    if (window.location.protocol === 'file:') {
      status.textContent = 'A consulta funciona no site publicado na Locaweb. O arquivo aberto no computador não executa a integração PHP.';
      return;
    }
    controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 55000);
    submit.disabled = true;
    submit.textContent = 'Consultando…';
    form.setAttribute('aria-busy', 'true');
    status.textContent = 'Consultando sua entrega…';
    try {
      const response = await fetch('./api/rastreamento.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({cnpj:document, nf}), signal: controller.signal, credentials: 'same-origin', cache: 'no-store'
      });
      const payload = await response.json().catch(() => null);
      if (!payload) throw new Error('O servidor não conseguiu carregar a integração de rastreamento. Tente novamente em alguns minutos. Referência: NVC-HTTP-' + response.status + '.');
      if (!response.ok || !payload?.ok) throw new Error(payload?.message || 'A consulta não está disponível agora. Tente novamente em alguns minutos.');
      const shipments = payload.shipments || [];
      const carriers = [...new Set(shipments.map(shipment => shipment.carrier || payload.carrier).filter(Boolean))];
      const carrier = carriers.length === 1 ? carriers[0] : (shipments.length ? '' : payload.carrier || '');
      render(shipments, payload.carrier || '');
      status.textContent = payload.message || (carrier ? `Informações fornecidas pela ${carrier}.` : 'Informações fornecidas pela transportadora.');
    } catch (error) {
      status.textContent = error.name === 'AbortError' ? 'Consulta interrompida. Você pode tentar novamente.' : error.message;
    } finally {
      clearTimeout(timeout);
      submit.disabled = false;
      submit.textContent = 'Consultar pedido';
      form.removeAttribute('aria-busy');
    }
  });
})();
