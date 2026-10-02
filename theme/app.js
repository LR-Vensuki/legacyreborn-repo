/*
 * Small progressive enhancements. ES3 on purpose: has to run in
 * Mobile Safari and Cydia on iOS 3-6. The pages work without it.
 */
(function (w, d) {
  if (!d.querySelector || !w.addEventListener) return;

  // Navigation bar title: centered like on iOS; when it would run into the
  // back button, move it right of the button (what UINavigationBar does).
  var bar = d.querySelector('.navbar');
  var back = bar && bar.querySelector('.back');
  var title = bar && bar.querySelector('h1');

  function fitTitle() {
    if (!back || !title || !back.offsetWidth) return;
    var side = back.offsetLeft + back.offsetWidth + 6;
    title.style.marginLeft = title.style.marginRight = side + 'px';
    if (title.scrollWidth > title.clientWidth) title.style.marginRight = '12px';
  }
  fitTitle();
  w.addEventListener('resize', fitTitle, false);

  // Package search on the home page.
  var input = d.getElementById('search');
  if (!input) return;

  var rows = d.querySelectorAll('[data-search]');
  var sections = d.querySelectorAll('[data-section]');
  var extras = d.querySelectorAll('[data-hide-on-search]');
  var empty = d.getElementById('no-results');

  function show(el, on) { el.style.display = on ? '' : 'none'; }

  function filter() {
    var query = input.value.toLowerCase().replace(/^\s+|\s+$/g, '');
    var words = query ? query.split(/\s+/) : [];
    var total = 0, i, j;

    for (i = 0; i < rows.length; i++) {
      var text = rows[i].getAttribute('data-search'), match = true;
      for (j = 0; j < words.length; j++) {
        if (text.indexOf(words[j]) < 0) { match = false; break; }
      }
      show(rows[i], match);
    }

    for (i = 0; i < sections.length; i++) {
      var items = sections[i].querySelectorAll('[data-search]'), first = null, count = 0;
      for (j = 0; j < items.length; j++) {
        items[j].className = items[j].className.replace(/\s*\btop\b/g, '');
        if (items[j].style.display !== 'none') {
          if (!first) first = items[j];
          count++;
        }
      }
      if (first) first.className += ' top'; // no separator above the first visible cell
      show(sections[i], count > 0);
      total += count;
    }

    for (i = 0; i < extras.length; i++) show(extras[i], !query);
    if (empty) show(empty, query && !total);
  }

  input.addEventListener('input', filter, false);
  input.addEventListener('keyup', filter, false);
  input.addEventListener('search', filter, false);
})(window, document);
