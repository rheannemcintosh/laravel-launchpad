<script setup lang="ts">
import { Rocket } from '@lucide/vue';
import type { HTMLAttributes } from 'vue';

// The app's icon, used in the sidebar, the sign-in pages, the home page and the
// dashboard. To change it, swap the Lucide icon here, and replace the browser icons
// in public/.
defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <Rocket :class="className" v-bind="$attrs" />
</template>
