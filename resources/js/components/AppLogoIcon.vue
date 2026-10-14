<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 44 44" :class="className" v-bind="$attrs" aria-label="TechPoint"><rect x="1" y="1" width="42" height="42" rx="11" fill="#11273c"/><path d="M10 12h23v6h-8v16h-7V18h-8z" fill="white"/><path d="M33 26v10m-5-5h10" stroke="#30bc94" stroke-width="3"/></svg></template>
