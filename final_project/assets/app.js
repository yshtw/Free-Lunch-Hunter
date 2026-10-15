'use strict';

const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
const announce = (message) => {
  const status = document.getElementById('live-status');
  status.textContent = message;
  status.hidden = false;
};

for (const form of document.querySelectorAll('[data-calendar-form]')) {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = form.querySelector('button');
    button.disabled = true;
    try {
      const response = await fetch(form.getAttribute('action'), {
        method: 'POST', body: new FormData(form),
        headers: {Accept: 'application/json', 'X-CSRF-Token': csrf},
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || '操作失敗，請重試。');
      const action = form.elements.namedItem('action');
      const joined = action.value === 'add';
      action.value = joined ? 'delete' : 'add';
      button.textContent = joined ? '✓ 已加入行事曆' : '＋ 加入行事曆';
      announce(joined ? '已加入行事曆。重新載入頁面即可查看更新後的清單。' : '已從行事曆移除。');
    } catch (error) {
      announce(error.message);
    } finally {
      button.disabled = false;
    }
  });
}

for (const form of document.querySelectorAll('[data-confirm-delete]')) {
  form.addEventListener('submit', (event) => {
    if (event.submitter?.name === 'delete' && !window.confirm('確定刪除這篇文章與相關留言？')) {
      event.preventDefault();
    }
  });
}

const editor = document.querySelector('[data-article-editor]');
if (editor) {
  const preview = document.querySelector('[data-preview]');
  editor.addEventListener('input', () => {
    preview.textContent = [editor.elements.title.value, editor.elements.content.value].join('\n\n');
  });
}
