const segmentEditor = document.querySelector('#segment-editor');
function openSegmentEditor(id = '', name = '') {
  segmentEditor.hidden = false;
  segmentEditor.elements.segment_id.value = id;
  segmentEditor.elements.segment_name.value = name;
  segmentEditor.elements.segment_name.focus();
}
document.querySelector('#add-segment')?.addEventListener('click', () => openSegmentEditor());
document.querySelector('#cancel-segment')?.addEventListener('click', () => { segmentEditor.hidden = true; });
document.querySelectorAll('[data-edit-segment]').forEach(button => button.addEventListener('click', () => openSegmentEditor(button.dataset.editSegment, button.dataset.segmentName)));
document.querySelectorAll('[data-delete-segment]').forEach(form => form.addEventListener('submit', async event => {
  event.preventDefault();
  if (await icctShowMessage({title:'Delete segment',text:`Delete “${form.dataset.segmentName}”? Segments assigned to devices or imported templates cannot be deleted.`,confirm:true,danger:true,accept:'Delete'})) {
    if (form.dataset.staticPreview) icctToast({title:'Preview',text:'Delete segments in the deployed plugin. Saved segments are unchanged.'});
    else form.submit();
  }
}));
