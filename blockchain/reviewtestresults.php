<link rel="stylesheet" href="js/quilljs/1.3.7/quill.snow.css">
<style>
/* Excel-like colors */
table.dataTable {
    border-collapse: collapse;
    font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
    font-size: 13px;
}

table.dataTable th,
table.dataTable td {
    border: 1px solid #ccc;
    padding: 6px 8px;
    text-align: center;
}

table.dataTable th {
    background-color: #f2f2f2;
    font-weight: bold;
}

table.dataTable tbody tr:nth-child(odd) {
    background-color: #ffffff;
}

table.dataTable tbody tr:nth-child(even) {
    background-color: #f9f9f9;
}

.dataTables_wrapper .dt-buttons {
    margin-bottom: 10px;
}

.container h5 {
    margin-bottom: 20px;
    font-weight: bold;
}

.mb-3 {
    margin-bottom: 15px;
}

.table th, .table td {
    text-align: center;
}

.table th {
    width: 120px; /* Set fixed width for column headers */
}

.table td {
max-width: 150px; /* Set maximum width for cells */
padding: 0; /* Remove padding to make space for content */
}

.scrollable-content {
    overflow: hidden; /* Initially hide overflow */
    white-space: nowrap; /* Prevent text wrapping */
    text-overflow: ellipsis; /* Show ellipsis for truncated text */
    display: block; /* Ensure block display for the content */
    max-width: 100%; /* Full width of the table cell */
    padding: 5px; /* Add padding inside the div */
    border: 1px solid #ccc; /* Subtle border for the content */
    background-color: #f9f9f9; /* Background color */
    color: #333; /* Text color */
    box-sizing: border-box; /* Include padding and border in width/height */
    font-family: Arial, sans-serif; /* Font style */
    font-size: 14px; /* Font size */
    max-height: 30px; /* Collapsed height */
    transition: max-height 0.3s ease; /* Smooth transition for expanding */
    cursor: pointer; /* Pointer cursor to indicate focusable element */
}

.scrollable-content:focus {
    overflow: visible; /* Show all content */
    white-space: normal; /* Allow text to wrap */
    max-height: none; /* Remove height restriction */
    outline: none; /* Remove default outline for better appearance */
    background-color: #fff; /* Optional: Change background color on focus */
    border-color: #007BFF; /* Optional: Highlight border on focus */
}

/* Responsive table design */
@media (max-width: 768px) {
    .table th, .table td {
        padding: 10px;
    }
}

.table {
    table-layout: fixed;
}

.table-bordered {
    border: 1px solid #ddd;
}

.table-striped tbody tr:nth-child(odd) {
    background-color: #f9f9f9;
}

#approveModal div {
    font-family: Arial, sans-serif; font-size: 14px; line-height: 1; padding: 5;
}

#approveModal {
      z-index: 1051; /* Default Bootstrap modals are at 1050 */
}

.wr-table { width: 100%; border-collapse: collapse; min-width: 1060px; }
.wr-table th { background: #17324d; color: #fff; padding: 12px 10px; text-align: left; font-size: 12px; letter-spacing: 0.04em; text-transform: uppercase; }
.wr-table td { padding: 12px 10px; border-bottom: 1px solid #e5edf5; font-size: 13px; color: #1f2937; vertical-align: top; }
.wr-table tr:hover { background: #f8fbfd; }
/* DataTables applies .dataTable at init - harden selectors so wr-table look wins */
table.wr-table.dataTable th { background: #17324d; color: #fff; padding: 12px 10px; text-align: left; font-size: 12px; letter-spacing: 0.04em; text-transform: uppercase; }
table.wr-table.dataTable td { padding: 12px 10px; border-bottom: 1px solid #e5edf5; font-size: 13px; color: #1f2937; vertical-align: top; }
table.wr-table.dataTable tbody tr:hover { background: #f8fbfd; }
/* Pagination - matches the wr-table design */
#pagination { margin-top: 14px; text-align: right; }
#pagination button {
    background: #fff; border: 1px solid #d4deea; color: #17324d;
    padding: 6px 12px; margin-left: 4px; border-radius: 4px;
    font-size: 13px; cursor: pointer;
}
#pagination button:hover:not(:disabled) { background: #e9eef4; }
#pagination button.active { background: #17324d; color: #fff; border-color: #17324d; }
#pagination button:disabled { opacity: .5; cursor: default; }
#pagination span { vertical-align: middle; }
.rich-editor { min-height: 72px; border: 1px solid #ced4da; border-radius: 4px; padding: 8px; background: #fff; font-size: 14px; line-height: 1.4; }
.rich-editor:focus { outline: 2px solid #86b7fe; border-color: #86b7fe; }
.quill-editor { background: #fff; font-size: 14px; }
.quill-editor .ql-editor { min-height: 72px; line-height: 1.4; }
.quill-editor .ql-container { border-radius: 0 0 4px 4px; }
.quill-editor .ql-toolbar { border-radius: 4px 4px 0 0; }
</style>
<i class="fas fa-project-diagram"></i>Audit Lab Result Workflow
<!-- Button to trigger modal -->
<div class="container mt-4">
    <div class="grid-container">
<div class="search-container">
    <input type="text" id="searchInput" class="form-control" placeholder="Search results..." onkeyup="searchTable()">
</div> 
<table id="testResultsTable" class="wr-table">
    <thead><tr>
        <th>Action</th><th>#</th><th>Sample Batch No</th><th>Sample ID</th><th>Date</th><th>No. of Tests</th><th>Tests / Results</th>
    </tr></thead><tbody></tbody>
</table>
<div class="pagination" id="pagination"></div>
</div>
</div>
<!-- Modal -->
<div class="modal fade" id="neutralityModal" tabindex="-1" role="dialog" aria-labelledby="neutralityModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="neutralityModalLabel">Neutrality Calculator</h5>
      </div>
      <div class="modal-body">
        <!-- Load the page as an iframe -->
        <iframe src="neutralitycalculator.php" style="width: 100%; height: 500px; border: none;"></iframe>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<!-- Modal -->
<div class="modal fade" id="tdscalculatorModal" tabindex="-1" role="dialog" aria-labelledby="tdscalculatorModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="tdscalculatorModalLabel">TDS Calculator</h5>
      </div>
      <div class="modal-body">
        <!-- Load the page as an iframe -->
        <iframe src="tdscalculator.php" style="width: 100%; height: 500px; border: none;"></iframe>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>


<!-- Modal for Approving Test Results -->
<div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveModalLabel" aria-hidden="true">
<div class="modal-dialog modal-xl"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title" id="approveModalLabel">Review and Approve Sample Block</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
<div class="modal-body">
<div class="row mb-3">
<div class="col-md-4"><label class="form-label fw-bold">Sample ID</label><input type="text" id="blockSampleID" class="form-control" readonly></div>
<div class="col-md-4"><label class="form-label fw-bold">Sample Batch No</label><input type="text" id="blockDocumentNo" class="form-control" readonly></div>
<div class="col-md-4"><label class="form-label fw-bold">Number of Tests</label><input type="text" id="blockTestCount" class="form-control" readonly></div>
</div>
<div class="table-responsive"><table class="sample-tests-table" style="width:100%;border-collapse:collapse;">
<thead><tr><th style="background:#17324d;color:#fff;padding:8px;">#</th><th style="background:#17324d;color:#fff;padding:8px;">Parameter</th><th style="background:#17324d;color:#fff;padding:8px;">Result</th><th style="background:#17324d;color:#fff;padding:8px;">Standard Limits</th></tr></thead>
<tbody id="sampleTestsBody"></tbody></table></div><div class="mt-3">
  <h6 class="fw-bold">COA Text Formatting</h6>
  <p class="small text-muted mb-2">Use the rich-text toolbar for bold, italic, underline, lists, superscript, subscript, and paragraph line breaks.</p>
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><label class="form-label fw-bold mb-1" for="ResultsInterpretationEditor">Interpretation of Analysis Results</label><button type="button" id="generateResultsInterpretationBtn" class="btn btn-outline-primary btn-sm" onclick="generateResultsInterpretation()"><i class="fas fa-wand-magic-sparkles"></i> Generate Interpretation with AI</button></div>
  <div class="small text-muted mb-2">AI prepares a draft using this sample’s Scope of Work, registered standard method, limits and recorded results. Review and edit the draft before saving.</div>
  <div id="ResultsInterpretationEditor" class="quill-editor mb-3"></div>
  <label class="form-label fw-bold mt-2" for="StatementOfConformityEditor">Statement of Conformity</label>
  <div id="StatementOfConformityEditor" class="quill-editor mb-3"></div>
  <label class="form-label fw-bold mt-2" for="NotesEditor">Notes</label>
  <div id="NotesEditor" class="quill-editor"></div>
</div>
<hr>
<div class="row align-items-end"><div class="col-md-5"><label for="approvalStatus" class="form-label fw-bold">Block Decision</label>
<select id="approvalStatus" name="approvalStatus" class="form-select" required><option value="">Select decision</option><option value="1">Approve Entire Sample</option><option value="4">Reject Entire Sample</option></select>
</div><div class="col-md-7 text-end"><button type="button" class="btn btn-success" onclick="submitApproval()"><i class="fas fa-check"></i> Apply Decision to All Tests</button></div></div>
<input type="hidden" id="blockHeaderID"><input type="hidden" id="flag" value="3">
</div></div></div></div>

<script src="js/quilljs/1.3.7/quill.min.js"></script>
<script>
window.quillInstances = window.quillInstances || {};
function initializeCoaQuillEditors(){
    var toolbar = [[{ header: [1, 2, false] }], ["bold", "italic", "underline"], [{ script: "sub" }, { script: "super" }], [{ list: "ordered" }, { list: "bullet" }], ["clean"]];
    ["ResultsInterpretationEditor", "StatementOfConformityEditor", "NotesEditor"].forEach(function(id){
        var element = document.getElementById(id);
        if (element && !window.quillInstances[id]) {
            window.quillInstances[id] = new Quill(element, { theme: "snow", modules: { toolbar: toolbar } });
        }
    });
}
initializeCoaQuillEditors();
var wrPage=1,wrPerPage=10,wrRows=[],wrQuery='',wrResults=[];
function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function resultValue(r){if(r.ResultType==='qualitativeField')return r.ResultStatus||'N/A';if(r.ResultType==='rangeField')return r.RangeResult||'N/A';return r.MRL_Result||'N/A';}
function wrMatches(row,q){return !q||row.textContent.toLowerCase().indexOf(q)!==-1;}
function wrRenderPage(){const tb=document.querySelector('#testResultsTable tbody');if(!tb)return;const v=wrRows.filter(r=>wrMatches(r,wrQuery)),tp=Math.max(1,Math.ceil(v.length/wrPerPage));if(wrPage>tp)wrPage=tp;const st=(wrPage-1)*wrPerPage;tb.innerHTML='';v.slice(st,st+wrPerPage).forEach(tr=>tb.appendChild(tr));wrRenderPager(tp,v.length);}
function wrRenderPager(tp,count){const box=document.getElementById('pagination');if(!box)return;let h='<span style="margin-right:8px;color:#17324d;font-size:13px;">'+count+' sample(s) - Page '+wrPage+' of '+tp+'</span>';h+='<button type="button"'+(wrPage===1?' disabled':'')+' onclick="wrGo('+(wrPage-1)+')">&#8592; Prev</button>';let a=Math.max(1,wrPage-4),z=Math.min(tp,a+9);if(z<tp&&z-a<9)a=Math.max(1,z-9);for(let i=a;i<=z;i++)h+='<button type="button" class="'+(i===wrPage?'active':'')+'" onclick="wrGo('+i+')">'+i+'</button>';if(z<tp)h+='<span style="color:#17324d;margin-left:4px;">&hellip;</span>';h+='<button type="button"'+(wrPage===tp?' disabled':'')+' onclick="wrGo('+(wrPage+1)+')">Next &#8594;</button>';box.innerHTML=h;}
function wrGo(p){wrPage=p;wrRenderPage();}
function searchTable(){const i=document.getElementById('searchInput');wrQuery=i?i.value.trim().toLowerCase():'';wrPage=1;wrRenderPage();}
function fetchTestResults(flag){const tb=document.querySelector('#testResultsTable tbody');tb.innerHTML='<tr><td colspan="7" class="text-center">Loading...</td></tr>';fetch('ajax/get_test_results.php?statusID='+encodeURIComponent(flag)+'&groupBySample=1&wholeSample=1').then(r=>r.json()).then(data=>{if(!data.success){wrRows=[];wrResults=[];const message=data.message||'No pending sample blocks were returned.';tb.innerHTML='<tr><td colspan="7" class="text-center">'+esc(message)+'</td></tr>';wrRenderPager(1,0);return;}wrResults=data.results||[];wrRows=[];wrResults.forEach((sample,index)=>{const tests=sample.tests||[];const names=tests.map(t=>'<li>'+esc(t.ParameterName)+'</li>').join('');const summary=tests.map(t=>'<span class="result-badge"><b>'+esc(t.ParameterName)+':</b> '+esc(resultValue(t))+'</span>').join('');const row=document.createElement('tr');row.innerHTML='<td><button class="btn btn-primary btn-sm" onclick="openApprovalModal('+index+')"><i class="fas fa-tasks"></i> Review / Approve</button></td><td>'+(index+1)+'</td><td>'+esc(sample.DocumentNo||'')+'</td><td><strong>'+esc(sample.SampleID||'')+'</strong></td><td>'+esc((sample.Date||'').split(' ')[0])+'</td><td>'+tests.length+'</td><td><ul class="test-list">'+names+'</ul><div>'+summary+'</div></td>';wrRows.push(row);});wrPage=1;wrRenderPage();}).catch(err=>{console.error(err);tb.innerHTML='<tr><td colspan="7" class="text-center text-danger">Error loading results.</td></tr>';});}
async function openApprovalModal(index){const sample=wrResults[index];if(!sample)return;document.getElementById('blockHeaderID').value=sample.HeaderID||'';document.getElementById('blockSampleID').value=sample.SampleID||'';document.getElementById('blockDocumentNo').value=sample.DocumentNo||'';document.getElementById('blockTestCount').value=(sample.tests||[]).length;document.getElementById('approvalStatus').value='';const body=document.getElementById('sampleTestsBody');body.innerHTML='';(sample.tests||[]).forEach((t,i)=>{const tr=document.createElement('tr');tr.innerHTML='<td style="padding:8px;border-bottom:1px solid #e5edf5;">'+(i+1)+'</td><td style="padding:8px;border-bottom:1px solid #e5edf5;"><strong>'+esc(t.ParameterName||'')+'</strong>'+(t.ParamCategory?'<br><small>'+esc(t.ParamCategory)+'</small>':'')+'</td><td style="padding:8px;border-bottom:1px solid #e5edf5;">'+esc(resultValue(t))+'</td><td style="padding:8px;border-bottom:1px solid #e5edf5;">'+esc('Limits: '+(t.Limits||'N/A')+' | Unit: '+(t.UnitOfMeasure||'N/A'))+'</td>';body.appendChild(tr);});const editors={ResultsInterpretation:'ResultsInterpretationEditor',StatementOfConformity:'StatementOfConformityEditor',Notes:'NotesEditor'};Object.values(editors).forEach(id=>{if(window.quillInstances[id])window.quillInstances[id].root.innerHTML='';});const modal=bootstrap.Modal.getOrCreateInstance(document.getElementById('approveModal'));modal.show();try{const url='ajax/sample_coa_text.php?SampleID='+encodeURIComponent(sample.SampleID||'')+'&HeaderID='+encodeURIComponent(sample.HeaderID||'');const response=await fetch(url,{cache:'no-store'});const data=await response.json();if(!data.success)throw new Error(data.message||'Unable to load saved COA text.');Object.keys(editors).forEach(key=>{if(window.quillInstances[editors[key]])window.quillInstances[editors[key]].root.innerHTML=(data.texts&&data.texts[key]!=null)?String(data.texts[key]):'';});}catch(error){console.error('Failed to preload COA text:',error);toastr.error('Could not preload Notes, Interpretation and Statement of Conformity. '+(error.message||''));}}
async function generateResultsInterpretation(){
    var sampleID=document.getElementById('blockSampleID').value;
    var headerID=document.getElementById('blockHeaderID').value;
    var editor=window.quillInstances.ResultsInterpretationEditor;
    var button=document.getElementById('generateResultsInterpretationBtn');
    if(!sampleID||!headerID){toastr.error('Sample information is missing.');return;}
    if(!editor){toastr.error('The Results Interpretation editor is not available.');return;}
    if(editor.getText().trim() && !window.confirm('Replace the current Results Interpretation with a new AI-generated draft?')) return;
    var originalButton=button.innerHTML;
    button.disabled=true;
    button.innerHTML='<i class="fas fa-spinner fa-spin"></i> Generating...';
    try{
        var formData=new FormData();
        formData.append('SampleID',sampleID);
        formData.append('HeaderID',headerID);
        var response=await fetch('ajax/generate_results_interpretation.php',{method:'POST',body:formData,cache:'no-store'});
        var data=await response.json();
        if(!response.ok||!data.success) throw new Error(data.message||'AI interpretation could not be generated.');
        editor.root.innerHTML=String(data.interpretation||'');
        toastr.success(data.message || 'AI interpretation draft inserted. Review it carefully before saving.');
    }catch(error){
        console.error('AI Results Interpretation failed:',error);
        toastr.error(error.message||'AI interpretation could not be generated.');
    }finally{
        button.disabled=false;
        button.innerHTML=originalButton;
    }
}
function normalizeApprovalHtml(value){var html=String(value==null?'':value);html=html.replace(/[\r\n]+/g,'');html=html.replace(/(?:<p>\s*(?:(?:<br\s*\/?\s*>)|&nbsp;|\s)*<\/p>\s*)+/gi,'');html=html.replace(/(?:<div>\s*<br\s*\/?\s*>\s*<\/div>\s*)+/gi,'');return html;}
function coaEditorHtml(value){const text=String(value==null?'':value);if(/<\/?[a-z][^>]*>/i.test(text))return text;return esc(text).replace(/\r\n|\r|\n/g,'<br>');}
function submitApproval(){const h=document.getElementById('blockHeaderID').value,s=document.getElementById('blockSampleID').value,a=document.getElementById('approvalStatus').value,f=document.getElementById('flag').value;if(!h||!s){toastr.error('Sample information is missing.');return;}if(!a){toastr.error('Please select a block decision.');return;}const fd=new FormData();for(let i=0;i<localStorage.length;i++){const k=localStorage.key(i);if(k==='user_id')fd.append(k,localStorage.getItem(k));}fd.set('blockApproval','1');fd.set('reviewBlockApproval','1');fd.set('HeaderID',h);fd.set('SampleID',s);fd.set('flag',f);fd.set('approvalStatus',a);fd.set('ResultsInterpretation',normalizeApprovalHtml(window.quillInstances.ResultsInterpretationEditor ? window.quillInstances.ResultsInterpretationEditor.root.innerHTML : ''));fd.set('StatementOfConformity',normalizeApprovalHtml(window.quillInstances.StatementOfConformityEditor ? window.quillInstances.StatementOfConformityEditor.root.innerHTML : ''));fd.set('Notes',normalizeApprovalHtml(window.quillInstances.NotesEditor ? window.quillInstances.NotesEditor.root.innerHTML : ''));if(!fd.get('user_id')){toastr.error('User identity was not found. Please log in again.');return;}const btn=document.querySelector('#approveModal .btn-success');if(btn)btn.disabled=true;fetch('ajax/approve_test_result_3.php',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{if(data.success){toastr.success(data.message);bootstrap.Modal.getOrCreateInstance(document.getElementById('approveModal')).hide();fetchTestResults(3);}else toastr.error(data.message||'Sample block approval failed.');}).catch(err=>{console.error(err);toastr.error('An error occurred while approving the sample block.');}).finally(()=>{if(btn)btn.disabled=false;});}
fetchTestResults(3);
var currentSampleID = '';

function buildCalculatorURL(baseURL, ions) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(ions)) {
        params.append(key, value);
    }
    return baseURL + '?' + params.toString();
}

function openNeutralityCalculator() {
    if (!currentSampleID) return;
    fetch(`ajax/get_sample_ions.php?sampleID=${currentSampleID}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && Object.keys(data.neutralityIons).length > 0) {
                const iframe = document.querySelector('#neutralityModal iframe');
                iframe.src = buildCalculatorURL('neutralitycalculator.php', data.neutralityIons);
            } else {
                const iframe = document.querySelector('#neutralityModal iframe');
                iframe.src = 'neutralitycalculator.php';
                toastr.info('No ion data found for this sample');
            }
            const neutralityModal = new bootstrap.Modal($('#neutralityModal'), { backdrop: 'static', keyboard: false });
            neutralityModal.show();
        });
}

function openTDSCalculator() {
    if (!currentSampleID) return;
    fetch(`ajax/get_sample_ions.php?sampleID=${currentSampleID}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && Object.keys(data.tdsElements).length > 0) {
                const iframe = document.querySelector('#tdscalculatorModal iframe');
                iframe.src = buildCalculatorURL('tdscalculator.php', data.tdsElements);
            } else {
                const iframe = document.querySelector('#tdscalculatorModal iframe');
                iframe.src = 'tdscalculator.php';
                toastr.info('No element data found for this sample');
            }
            const tdscalculatorModal = new bootstrap.Modal($('#tdscalculatorModal'), { backdrop: 'static', keyboard: false });
            tdscalculatorModal.show();
        });
}

$('#CMDneutralityModal').click(openNeutralityCalculator);
$('#CMDtdscalculatorModal').click(openTDSCalculator);




</script>