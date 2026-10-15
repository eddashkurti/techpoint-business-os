<script setup lang="ts">
import {Head,useForm} from '@inertiajs/vue3';
import {computed,ref} from 'vue';
const p=defineProps<{accounts:any[];batches:any[]}>();
const kind=ref('products');const form=useForm<any>({file:null,account_id:''});const previewHeaders=ref<string[]>([]);const previewRows=ref<any[]>([]);const previewTotal=ref(0);const previewError=ref('');
const definitions:any={products:{label:'Produktet',columns:'sku, name, unit, purchase_unit, unit_conversion, purchase_currency, purchase_price, selling_price, tax_rate, minimum_stock'},customers:{label:'Klientët',columns:'name, email, phone, country, tax_number, address'},suppliers:{label:'Furnitorët',columns:'name, email, phone, country, tax_number, address'},'opening-balances':{label:'Bilancet hapëse',columns:'accounting_date, reference, description, account_code, debit, credit'},'historical-records':{label:'Regjistrime historike',columns:'accounting_date, reference, description, account_code, debit, credit'},bank:{label:'Ekstrakti bankar',columns:'date, external_reference, amount, description'}};
const current=computed(()=>definitions[kind.value]);
function resetImport(){form.reset('file','account_id');previewHeaders.value=[];previewRows.value=[];previewTotal.value=0;previewError.value='';}
async function preview(){if(!form.file)return;previewError.value='';const data=new FormData();data.append('file',form.file);const token=document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')??'';const response=await fetch('/imports/'+kind.value+'/preview',{method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':token},body:data});const payload=await response.json().catch(()=>({}));if(!response.ok){previewHeaders.value=[];previewRows.value=[];previewTotal.value=0;previewError.value=payload.message??'Skedari nuk mund të validohet.';return;}previewHeaders.value=payload.headers??[];previewRows.value=payload.rows??[];previewTotal.value=payload.total??0;}
function submit(){form.post('/imports/'+kind.value,{forceFormData:true,preserveScroll:true,onSuccess:()=>{form.reset('file');previewHeaders.value=[];previewRows.value=[];previewTotal.value=0;}});}
</script>
<template>
    <Head title="Importet" />
    <div class="tp-page-heading"><div><div class="tp-eyebrow">MIGRIM I KONTROLLUAR I TË DHËNAVE</div><h1>Importet CSV</h1><p>Skedari validohet plotësisht para ruajtjes. I njëjti skedar nuk mund të importohet dy herë.</p></div></div>
    <section class="tp-panel">
        <form @submit.prevent="submit">
            <div class="tp-fields">
                <label class="tp-field"><span>Lloji</span><select v-model="kind" @change="resetImport"><option v-for="(definition,key) in definitions" :key="key" :value="key">{{definition.label}}</option></select></label>
                <label v-if="kind==='bank'" class="tp-field"><span>Llogaria bankare</span><select v-model="form.account_id" required><option value="">Zgjidhni…</option><option v-for="account in accounts" :key="account.id" :value="account.id">{{account.code}} · {{account.name}}</option></select></label>
                <label class="tp-field wide"><span>Skedari CSV (deri 10 MB)</span><input type="file" accept=".csv,text/csv" required @change="form.file=($event.target as HTMLInputElement).files?.[0]??null" /></label>
            </div>
            <div class="tp-notice"><strong>Kolonat e kërkuara</strong><p>{{current.columns}}</p><p>Përdorni numra me pikë dhjetore dhe tituj të saktë të kolonave. Importet e bilanceve dhe historikut postojnë vetëm regjistra të balancuar në periudhë të hapur.</p></div>
            <p v-for="(error,key) in form.errors" :key="key" class="tp-error">{{error}}</p>
            <p v-if="previewError" class="tp-error">{{previewError}}</p>
            <div class="tp-actions">
                <a class="tp-button secondary" :href="'/imports/'+kind+'/template'">Shkarko modelin CSV</a>
                <button type="button" class="tp-button secondary" :disabled="!form.file" @click="preview">Parapamje dhe validim</button>
                <button class="tp-button" :disabled="form.processing||!form.file">Valido dhe importo {{current.label}}</button>
            </div>
        </form>
        <div v-if="previewRows.length" class="tp-table-wrap">
            <h2>Parapamje: {{previewTotal}} rreshta</h2>
            <table><thead><tr><th v-for="header in previewHeaders" :key="header">{{header}}</th></tr></thead><tbody><tr v-for="(row,index) in previewRows" :key="index"><td v-for="header in previewHeaders" :key="header">{{row[header]}}</td></tr></tbody></table>
        </div>
    </section>
    <section class="tp-panel"><h2>Importet e fundit</h2><div v-if="batches.length" class="tp-table-wrap"><table><thead><tr><th>#</th><th>Lloji</th><th>Skedari</th><th>Rreshtat</th><th>Koha</th></tr></thead><tbody><tr v-for="batch in batches" :key="batch.id"><td>{{batch.id}}</td><td>{{batch.kind}}</td><td>{{batch.filename}}</td><td>{{batch.row_count}}</td><td>{{batch.created_at}}</td></tr></tbody></table></div><p v-else class="tp-muted">Nuk ka importe të ruajtura.</p></section>
</template>
