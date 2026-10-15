<script setup lang="ts">
import { Link, usePage, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
const page=usePage<any>(); const open=ref(false);
const permissions=computed<string[]>(()=>page.props.auth?.permissions??[]);
const groups=[
 ['Biznesi',[['Përmbledhja','/dashboard','dashboard.view'],['Produktet','/products','inventory.view'],['Kategoritë','/categories','inventory.view'],['Lëvizjet e stokut','/stock','inventory.view'],['Numërimet','/counts','inventory.view'],['Blerjet dhe importet','/purchases','purchasing.view'],['Kostot pas pranimit','/cost-adjustments','purchasing.view'],['Kthimet te furnitorët','/supplier-returns','purchasing.view'],['Furnitorët','/suppliers','purchasing.view']]],
 ['Shitjet',[['Shitjet','/sales','sales.view'],['POS / Barkodi','/pos','sales.manage'],['Kuponet dhe promocionet','/promotions','sales.view'],['Klientët','/customers','sales.view'],['Kthimet','/returns','sales.view'],['Kreditimet e dërgesës','/delivery-credits','sales.view'],['Dërgesat','/deliveries','sales.view']]],
 ['Financat',[['Faturat dhe shpenzimet','/bills','finance.view'],['Kreditimet e furnitorëve','/supplier-credits','finance.view'],['Parapagimet e klientëve','/customer-deposits','finance.view'],['Shpenzime periodike','/recurring','finance.view'],['Korrierët','/couriers','finance.view'],['Rakordimi i korrierëve','/settlements','finance.view'],['Draftet kontabël','/drafts','accounting.view'],['Ditari kontabël','/journals','accounting.view'],['Plani kontabël','/accounts','accounting.view'],['Periudhat','/periods','accounting.view'],['Raportet','/reports','reports.view'],['Banka','/bank','finance.view'],['Buxheti','/budgets','reports.view']]],
 ['Administrimi',[['Integrimet','/integrations','integrations.view'],['Dokumentet fiskale','/fiscal','integrations.view'],['Importet CSV','/imports','settings.manage'],['API tokens','/api-tokens','users.manage'],['Përdoruesit','/users','users.manage'],['Rolet','/roles','users.manage'],['Konfigurimi','/business-settings','settings.manage'],['Auditimi','/audit','audit.view']]],
] as [string,string[][]][];
const allowed=(p:string)=>permissions.value.includes(p);
</script>
<template>
 <div class="tp-shell">
  <aside :class="['tp-sidebar',{open}]">
   <Link href="/dashboard" class="tp-brand"><span class="tp-mark">T<span>+</span></span><span>TechPoint<small>BUSINESS OS</small></span></Link>
   <nav aria-label="Navigimi kryesor"><template v-for="[label,items] in groups" :key="label"><section v-if="items.some(i=>allowed(i[2]))"><h2>{{label}}</h2><Link v-for="item in items.filter(i=>allowed(i[2]))" :key="item[1]" :href="item[1]" :class="{active:page.url===item[1]||page.url.startsWith(item[1]+'/')}" @click="open=false"><span class="nav-dot"></span>{{item[0]}}</Link></section></template></nav>
   <div class="tp-sidebar-bottom"><span class="status-dot"></span> Depoja qendrore · EUR <small>Koha e biznesit: Prishtinë</small></div>
  </aside>
  <div v-if="open" class="tp-scrim" @click="open=false"></div>
  <div class="tp-main"><header class="tp-topbar"><button class="tp-menu" aria-label="Hap navigimin" @click="open=!open">☰</button><div><span class="tp-eyebrow">TECHPOINT KOSOVA</span><strong>{{page.props.title??'Business OS'}}</strong></div><div class="tp-user"><span class="tp-avatar">{{String(page.props.auth?.user?.name??'T').slice(0,1)}}</span><span>{{page.props.auth?.user?.name}}<small>Llogari e autorizuar</small></span><button class="tp-text-btn" @click="router.post('/logout')">Dil</button></div></header>
   <main class="tp-content"><div v-if="page.props.flash?.success" role="status" class="tp-success">{{page.props.flash.success}}</div><slot /></main>
   <footer class="tp-footer">TechPoint Business OS <span>Një depo · Regjistra të auditueshëm</span></footer>
  </div>
 </div>
</template>
