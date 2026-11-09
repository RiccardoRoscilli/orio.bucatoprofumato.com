import './bootstrap';
import Alpine from 'alpinejs';
import cassa from './cassa';
import customerModal from './customer-modal';
import closure from './closure';
import { emitReceipt } from './fiscal/receipt';
import * as axonPrinter from './fiscal/axon';

window.Alpine = Alpine;
window.emitReceipt = emitReceipt;
window.axonPrinter = axonPrinter;
Alpine.data('cassa', cassa);
Alpine.data('customerModal', customerModal);
Alpine.data('closure', closure);
Alpine.start();
