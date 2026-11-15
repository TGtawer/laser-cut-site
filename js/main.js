/* ТСМ ДВ — скрипты лендинга. Без зависимостей. */
(function () {
  'use strict';

  // TODO(#8) Список форматов должен совпадать с accept в index.html и allowed_ext в config.php
  var ALLOWED_EXT = ['dxf', 'step', 'stp', 'iges', 'igs'];
  var MAX_FILES = 10;
  var MAX_TOTAL = 20 * 1024 * 1024; // 20 МБ суммарно
  var PHONE_TEXT = '+7 (914) 790-21-95';

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  /* ---------- Год в подвале ---------- */
  $$('[data-year]').forEach(function (el) { el.textContent = String(new Date().getFullYear()); });

  /* ---------- Мобильное меню ---------- */
  (function initNav() {
    var burger = $('.burger');
    var nav = $('#nav');
    if (!burger || !nav) return;
    var icon = $('use', burger);
    var desktop = window.matchMedia('(min-width: 1180px)');

    function setOpen(open) {
      burger.setAttribute('aria-expanded', String(open));
      burger.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
      if (icon) icon.setAttribute('href', open ? '#i-x' : '#i-menu');
      nav.classList.toggle('is-open', open);
      document.body.classList.toggle('is-locked', open);
    }

    burger.addEventListener('click', function () {
      setOpen(burger.getAttribute('aria-expanded') !== 'true');
    });
    nav.addEventListener('click', function (e) {
      if (e.target.closest('a')) setOpen(false);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && nav.classList.contains('is-open')) {
        setOpen(false);
        burger.focus();
      }
    });
    var onChange = function (e) { if (e.matches) setOpen(false); };
    if (desktop.addEventListener) desktop.addEventListener('change', onChange);
    else if (desktop.addListener) desktop.addListener(onChange);
  })();

  /* ---------- Кнопки «Рассчитать» подставляют услугу в форму ---------- */
  (function initServiceButtons() {
    var select = $('#f-service');
    if (!select) return;
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-service]');
      if (btn) select.value = btn.getAttribute('data-service');
    });
  })();

  /* ---------- Маска телефона +7 (XXX) XXX-XX-XX ---------- */
  function phoneDigits(value) {
    var d = String(value).replace(/\D/g, '');
    if (!d) return '';
    if (d.charAt(0) === '8') d = '7' + d.slice(1);
    else if (d.charAt(0) !== '7') d = '7' + d;
    return d.slice(0, 11);
  }

  function formatPhone(value) {
    var d = phoneDigits(value);
    if (!d) return '';
    var out = '+7';
    if (d.length > 1) out += ' (' + d.slice(1, 4);
    if (d.length >= 4) out += ')';
    if (d.length > 4) out += ' ' + d.slice(4, 7);
    if (d.length > 7) out += '-' + d.slice(7, 9);
    if (d.length > 9) out += '-' + d.slice(9, 11);
    return out;
  }

  (function initPhoneMask() {
    var input = $('#f-phone');
    if (!input) return;

    input.addEventListener('input', function (e) {
      // При удалении не переформатируем, иначе нельзя стереть скобку или дефис
      if (e.inputType && e.inputType.indexOf('delete') === 0) return;
      var value = input.value;
      var pos = input.selectionStart == null ? value.length : input.selectionStart;
      var atEnd = pos === value.length;
      var digitsBefore = value.slice(0, pos).replace(/\D/g, '').length;
      var formatted = formatPhone(value);
      input.value = formatted;
      if (!atEnd && document.activeElement === input) {
        var i = 0;
        var n = 0;
        while (i < formatted.length && n < digitsBefore) {
          if (/\d/.test(formatted.charAt(i))) n++;
          i++;
        }
        input.setSelectionRange(i, i);
      }
    });

    input.addEventListener('blur', function () {
      input.value = formatPhone(input.value);
      if (input.value === '+7') input.value = '';
    });
  })();

  /* ---------- Утилиты ---------- */
  function fileExt(name) {
    var i = name.lastIndexOf('.');
    return i === -1 ? '' : name.slice(i + 1).toLowerCase();
  }

  function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' Б';
    if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' КБ';
    return (bytes / 1024 / 1024).toFixed(1).replace('.', ',') + ' МБ';
  }

  function pluralFiles(n) {
    var m10 = n % 10;
    var m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return n + ' файл';
    if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return n + ' файла';
    return n + ' файлов';
  }

  function svgIcon(id) {
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('class', 'icon');
    svg.setAttribute('aria-hidden', 'true');
    var use = document.createElementNS(ns, 'use');
    use.setAttribute('href', '#' + id);
    svg.appendChild(use);
    return svg;
  }

  /* ---------- Форма заявки ---------- */
  (function initForm() {
    var form = $('#order-form');
    if (!form) return;

    var fileInput = $('#f-files', form);
    var dropzone = $('.dropzone', form);
    var fileList = $('#f-files-list', form);
    var statusBox = $('#f-status', form);
    var submitBtn = $('.form__submit', form);
    var files = [];
    var sending = false;

    /* Ошибки полей */
    function setError(name, message) {
      var el = form.elements[name];
      var input = el && el.length && !el.tagName ? el[0] : el;
      var box = $('#f-' + name + '-error', form);
      if (name === 'files') input = fileInput;
      if (input) {
        if (message) input.setAttribute('aria-invalid', 'true');
        else input.removeAttribute('aria-invalid');
      }
      if (box) {
        box.textContent = message || '';
        box.hidden = !message;
      }
    }

    function clearErrors() {
      ['name', 'phone', 'email', 'consent', 'files'].forEach(function (n) { setError(n, ''); });
    }

    /* Файлы */
    function totalSize() {
      return files.reduce(function (sum, f) { return sum + f.size; }, 0);
    }

    function validateFiles() {
      if (files.length > MAX_FILES) return 'Можно прикрепить не больше ' + MAX_FILES + ' файлов.';
      if (totalSize() > MAX_TOTAL) return 'Суммарный размер файлов — не больше 20 МБ. Уберите часть файлов или пришлите их на почту.';
      return '';
    }

    function renderFiles() {
      fileList.textContent = '';
      fileList.hidden = files.length === 0;
      files.forEach(function (file, index) {
        var li = document.createElement('li');
        li.appendChild(svgIcon('i-file'));

        var name = document.createElement('span');
        name.className = 'file-list__name';
        name.textContent = file.name;
        name.title = file.name;
        li.appendChild(name);

        var size = document.createElement('span');
        size.className = 'file-list__size';
        size.textContent = formatSize(file.size);
        li.appendChild(size);

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'file-list__remove';
        remove.setAttribute('aria-label', 'Удалить файл ' + file.name);
        remove.appendChild(svgIcon('i-x'));
        remove.addEventListener('click', function () {
          files.splice(index, 1);
          renderFiles();
          setError('files', validateFiles());
          fileInput.focus();
        });
        li.appendChild(remove);

        fileList.appendChild(li);
      });

      if (files.length) {
        var total = document.createElement('li');
        total.className = 'file-list__total';
        total.textContent = pluralFiles(files.length) + ', ' + formatSize(totalSize()) + ' из 20 МБ';
        fileList.appendChild(total);
      }
    }

    function addFiles(list) {
      var rejected = [];
      Array.prototype.forEach.call(list, function (file) {
        if (ALLOWED_EXT.indexOf(fileExt(file.name)) === -1) {
          rejected.push(file.name);
          return;
        }
        var duplicate = files.some(function (f) {
          return f.name === file.name && f.size === file.size && f.lastModified === file.lastModified;
        });
        if (!duplicate) files.push(file);
      });
      renderFiles();
      if (rejected.length) {
        setError('files', 'Формат не подходит: ' + rejected.join(', ') + '. Принимаем DXF, STEP, IGES.');
      } else {
        setError('files', validateFiles());
      }
    }

    fileInput.addEventListener('change', function () {
      addFiles(fileInput.files);
      fileInput.value = ''; // чтобы можно было выбрать тот же файл повторно
    });

    ['dragenter', 'dragover'].forEach(function (type) {
      dropzone.addEventListener(type, function (e) {
        e.preventDefault();
        dropzone.classList.add('is-dragover');
      });
    });
    ['dragleave', 'drop'].forEach(function (type) {
      dropzone.addEventListener(type, function (e) {
        e.preventDefault();
        dropzone.classList.remove('is-dragover');
      });
    });
    dropzone.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files.length) addFiles(e.dataTransfer.files);
    });

    /* Проверка полей */
    function validate() {
      var errors = {};
      var name = form.elements.name.value.trim();
      var phone = phoneDigits(form.elements.phone.value);
      var email = form.elements.email.value.trim();

      if (!name) errors.name = 'Укажите имя.';
      if (phone.length !== 11) errors.phone = 'Укажите номер телефона полностью.';
      if (email && !/^[^\s@<>,;"]+@[^\s@<>,;"]+\.[^\s@<>,;"]+$/.test(email)) errors.email = 'Проверьте адрес почты.';
      if (!form.elements.consent.checked) errors.consent = 'Нужно согласие на обработку персональных данных.';
      var filesError = validateFiles();
      if (filesError) errors.files = filesError;
      return errors;
    }

    function showErrors(errors) {
      clearErrors();
      var first = null;
      ['name', 'phone', 'email', 'files', 'consent'].forEach(function (n) {
        if (!errors[n]) return;
        setError(n, errors[n]);
        if (!first) first = n === 'files' ? fileInput : form.elements[n];
      });
      if (first) first.focus();
      return !!first;
    }

    // Снимаем ошибку, когда пользователь исправляет поле
    form.addEventListener('input', function (e) {
      var n = e.target.name;
      if (!n || n === 'files[]') return; // ошибки файлов обновляет addFiles()
      if (e.target.getAttribute('aria-invalid') === 'true') setError(n, '');
    });
    form.addEventListener('change', function (e) {
      if (e.target.name === 'consent' && e.target.checked) setError('consent', '');
    });

    /* Статус отправки */
    function showStatus(type, title, text) {
      statusBox.textContent = '';
      if (!type) {
        statusBox.hidden = true;
        return;
      }
      statusBox.className = 'form__status' + (type === 'error' ? ' is-error' : ' is-success');
      var strong = document.createElement('strong');
      strong.textContent = title;
      statusBox.appendChild(strong);
      if (text) statusBox.appendChild(document.createTextNode(text));
      statusBox.hidden = false;
    }

    function setSending(state) {
      sending = state;
      submitBtn.disabled = state;
      submitBtn.textContent = state ? 'Отправляем…' : 'Отправить заявку';
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (sending) return;
      showStatus('');

      if (showErrors(validate())) return;

      var data = new FormData(form);
      data.delete('files[]');
      files.forEach(function (file) { data.append('files[]', file, file.name); });
      // Сколько секунд прошло с загрузки страницы — для антиспам-проверки на сервере
      data.set('t', String(Math.round(performance.now() / 1000)));
      data.set('phone', formatPhone(form.elements.phone.value));

      setSending(true);
      fetch(form.getAttribute('action'), {
        method: 'POST',
        body: data,
        headers: { Accept: 'application/json' }
      })
        .then(function (res) {
          return res.json().catch(function () { return null; }).then(function (json) {
            return { ok: res.ok, json: json };
          });
        })
        .then(function (result) {
          var json = result.json || {};
          if (result.ok && json.ok) {
            form.reset();
            files = [];
            renderFiles();
            clearErrors();
            showStatus('success', 'Заявка отправлена.', ' ' + (json.message || 'Мы свяжемся с вами.'));
            return;
          }
          if (json.errors) showErrors(json.errors);
          showStatus('error', 'Заявка не отправлена.', ' ' + (json.message || 'Попробуйте ещё раз или позвоните нам: ' + PHONE_TEXT + '.'));
        })
        .catch(function () {
          showStatus('error', 'Нет связи с сервером.', ' Проверьте интернет и попробуйте ещё раз или позвоните нам: ' + PHONE_TEXT + '.');
        })
        .then(function () { setSending(false); });
    });
  })();

  /* ---------- Лайтбокс галереи ---------- */
  (function initLightbox() {
    var dialog = $('#lightbox');
    var links = $$('.gallery__link');
    // Без поддержки <dialog> ссылки просто открывают фото
    if (!dialog || !links.length || typeof dialog.showModal !== 'function') return;

    var img = $('.lightbox__img', dialog);
    var caption = $('.lightbox__caption', dialog);
    var index = 0;

    function show(i) {
      index = (i + links.length) % links.length;
      var link = links[index];
      var thumb = $('img', link);
      var figcaption = $('figcaption', link.closest('figure'));
      img.removeAttribute('src');
      img.width = Number(link.getAttribute('data-width')) || 1280;
      img.height = Number(link.getAttribute('data-height')) || 960;
      img.alt = thumb ? thumb.alt : '';
      img.src = link.getAttribute('data-full') || link.href;
      caption.textContent = figcaption ? figcaption.textContent.trim() : '';
    }

    links.forEach(function (link, i) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        show(i);
        dialog.showModal();
        document.body.classList.add('is-locked');
      });
    });

    dialog.addEventListener('close', function () {
      document.body.classList.remove('is-locked');
      links[index].focus();
    });

    $('.lightbox__close', dialog).addEventListener('click', function () { dialog.close(); });
    $('.lightbox__prev', dialog).addEventListener('click', function () { show(index - 1); });
    $('.lightbox__next', dialog).addEventListener('click', function () { show(index + 1); });

    // Клик по затемнённому фону закрывает
    dialog.addEventListener('click', function (e) {
      if (e.target === dialog) dialog.close();
    });

    dialog.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') show(index - 1);
      if (e.key === 'ArrowRight') show(index + 1);
    });

    // Свайп на телефоне
    var startX = null;
    dialog.addEventListener('touchstart', function (e) {
      startX = e.touches.length === 1 ? e.touches[0].clientX : null;
    }, { passive: true });
    dialog.addEventListener('touchend', function (e) {
      if (startX === null) return;
      var dx = e.changedTouches[0].clientX - startX;
      startX = null;
      if (Math.abs(dx) > 50) show(dx > 0 ? index - 1 : index + 1);
    });
  })();
})();
