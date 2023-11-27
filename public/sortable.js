if (typeof Backend !== 'undefined') {
    Backend.makeListViewSortable = function(ul) {
        var ds = new Scroller(document.getElement('body'), {
            onChange: function(x, y) {
                this.element.scrollTo(this.element.getScroll().x, y);
            }
        });

        var list = new Sortables(ul, {
            constrain: true,
            opacity: 0.6,
            onStart: function() {
                ds.start();
            },
            onComplete: function() {
                ds.stop();
            },
            handle: '.drag-handle'
        });

        list.addEvent('complete', function(el) {
            var id, pid, req, href;

            if (el.getPrevious('li')) {
                id = el.get('id').replace(/li_/, '');
                pid = el.getPrevious('li').get('id').replace(/li_/, '');
                req = window.location.search.replace(/id=[0-9]*!/, 'id=' + id) + '&act=cut&mode=1&pid=' + pid;
                href = window.location.href.replace(/\?.*$/, '');
                new Request.Contao({'url':href + req, 'followRedirects':false}).get();
            } else if (el.getParent('ul')) {
                id = el.get('id').replace(/li_/, '');
                pid = el.getParent('ul').get('id').replace(/ul_/, '');
                req = window.location.search.replace(/id=[0-9]*/, 'id=' + id) + '&act=cut&mode=2&pid=' + pid;
                href = window.location.href.replace(/\?.*$/, '');
                new Request.Contao({'url':href + req, 'followRedirects':false}).get();
            }
        });
    }
}