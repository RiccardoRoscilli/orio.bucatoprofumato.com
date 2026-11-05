// Testi italiani comuni alle DataTable di orio
export const italian = (things, fields) => ({
    search: '',
    searchPlaceholder: `Cerca: più parole separate da spazio (${fields})`,
    lengthMenu: '_MENU_ per pagina',
    info: `_START_–_END_ di _TOTAL_ ${things}`,
    infoEmpty: `Nessun risultato`,
    infoFiltered: '(filtrati da _MAX_)',
    zeroRecords: 'Nessun risultato',
    processing: 'Caricamento…',
    paginate: { first: '«', last: '»', next: '›', previous: '‹' },
});
