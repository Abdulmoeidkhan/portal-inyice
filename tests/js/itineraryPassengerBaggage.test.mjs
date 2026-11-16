import test from 'node:test';
import assert from 'node:assert/strict';
import { itineraryData, itineraryPdf } from '../../resources/js/services/itinerary.js';

test('segment baggage keeps passenger indexes and the PDF lists allowances beneath each flight', async () => {
  const data = itineraryData({meta:{passengers:[{name:''},{name:'Adult',ticket_no:'1234567890123'},{name:'Infant'}],flights:[
    {from:'KHI',to:'ISB',passenger_baggage:['99K','30K','10K']},
    {from:'ISB',to:'KHI',passenger_baggage:['99K','45K','10K']},
  ]}});
  assert.deepEqual(data.passengerSections.map(p=>[p.number,p.segments.map(s=>s.baggage)]),[[2,['30K','45K']],[3,['10K','10K']]]);
  const pdf = await itineraryPdf(data);
  const output = pdf.output();
  for(const label of ['Adult - 1234567890123','KHI','ISB','PAX 2 30K, PAX 3 10K','PAX 2 45K, PAX 3 10K']) assert.ok(output.includes(label),label);
  assert.equal(output.split('Adult').length - 1, 1);
  assert.equal(output.split('Infant').length - 1, 1);
  assert.ok(!output.includes('Passenger Name'));
  assert.ok(!output.includes('99K'));
});
