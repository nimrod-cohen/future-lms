JSUtils.domReady(() => {
  const btn = document.querySelector('#generate-whatsapp-group');
  if (!btn) return;

  btn.addEventListener('click', async e => {
    var result = { success: false };
    try {
      e.target.disabled = true;

      const webinarId = e.target.dataset.webinarId;
      result = await JSUtils.fetch(ajaxurl, {
        action: 'generate_whatsapp_group',
        webinar_id: webinarId
      });

      if (result.success) {
        notifications.show(result.data.message, 'success');
        e.target.disabled = true;
        document.querySelector('#whatsapp-group-id').textContent = result.data.group_id;
      } else {
        notifications.show(result.data, 'error');
      }
    } finally {
      if (!result?.success) e.target.disabled = false;
    }
  });
});

JSUtils.domReady(() => {
  const btn = document.querySelector('#process-webinar-participance');
  if (!btn) return;

  btn.addEventListener('click', async e => {
    var result = { success: false };

    try {
      e.target.disabled = true;
      e.target.classList.add('loading');

      const webinarId = e.target.dataset.webinarId;
      const timestamp = document.querySelector('#webinar-show-offer-time').value;

      if (!timestamp) {
        notifications.show('Please enter a valid show offer time', 'error');
        return;
      }

      result = await JSUtils.fetch(ajaxurl, {
        action: 'process_webinar_participance',
        webinar_id: webinarId,
        timestamp: timestamp.replace('T', ' ')
      });

      if (result.success) {
        notifications.show(result.data.message, 'success');
        e.target.disabled = true;
        document.querySelector('#show-offer-datetime').textContent = result.data.datetime;
      } else {
        notifications.show(result.data, 'error');
      }
    } finally {
      if (!result?.success) e.target.disabled = false;
      e.target.classList.remove('loading');
    }
  });
});
