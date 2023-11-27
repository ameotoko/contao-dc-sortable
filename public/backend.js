window.AppBackend = {
  makeSortable: function(tbody) {
    const ds = new Scroller(document.getElement('body'), {
      onChange: function(x, y) {
        this.element.scrollTo(this.element.getScroll().x, y);
      }
    });

    const list = new Sortables(tbody, {
      constrain: true,
      opacity: 0.6,
      onStart: function(el) {
        ds.start();
        el.addClass('dragging');
      },
      onComplete: function(el) {
        ds.stop();
        el.removeClass('dragging');
      },
      handle: '.drag-handle'
    });

    list.active = false;

    list.addEvent('start', function() {
      list.active = true;
    });

    list.addEvent('complete', function(el) {
      if (!list.active) return;
      let id, pid, req, href;

      id = el.get('data-id');

      if (el.getPrevious('tr')) {
        // pid is sorting value of the element, after which we drop
        pid = el.getPrevious('tr').get('data-id');
      } else if (el.getParent('tbody')) {
        // pid is 0 if this is the very top, otherwise same as above but preceding element is the last on the previous page
        pid = el.getParent('tbody').get('data-id');
      }

      if (id && pid) {
        req = window.location.search.replace(/id=[0-9]*/, 'id=' + id) + '&act=cut&pid=' + pid;
        href = window.location.href.replace(/\?.*$/, '');
        new Request.Contao({'url':href + req, 'followRedirects':false}).get();
      }
    });
  }
}
